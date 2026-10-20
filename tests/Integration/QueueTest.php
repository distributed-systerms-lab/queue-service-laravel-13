<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Queue\Broker;
use App\Queue\Envelope;
use App\Queue\IdentityConflict;
use App\Queue\PublishRejected;
use App\Queue\RabbitPublisher;
use App\Queue\ReceiptStore;
use App\Queue\Settings;
use App\Queue\Unroutable;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Illuminate\Foundation\Testing\TestCase;
use Symfony\Component\Process\Process;

final class QueueTest extends TestCase
{
    private Settings $settings;
    private Broker $broker;
    private AbstractConnection $connection;
    private ReceiptStore $store;
    private array $processes = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::assertNotEmpty(getenv('AMQP_URL'), 'Integration tests require AMQP_URL; use make integration');
        self::assertNotEmpty(getenv('DATABASE_URL'), 'Integration tests require DATABASE_URL; use make integration');
        $queue = 'test-'.bin2hex(random_bytes(8));
        $this->settings = new Settings(array_replace(config('queue_lab'), [
            'queues' => $queue, 'queue' => $queue, 'max_retries' => 2,
            'max_queue_length' => 10000, 'retry_delay' => '10ms',
        ]));
        $this->broker = new Broker($this->settings);
        $this->connection = $this->broker->connect();
        $this->broker->declare($this->connection);
        $this->store = new ReceiptStore($this->app['db']->connection(), $this->settings);
        $this->store->migrate();
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->processes) as $process) {
            if ($process->isRunning()) { $process->stop(3, SIGTERM); }
        }
        if (isset($this->connection)) {
            $channel = $this->connection->channel();
            $channel->queue_delete($this->settings->queue);
            $channel->queue_delete($this->settings->queue.'.dead');
            $this->connection->close();
        }
        if (isset($this->app)) {
            $this->app['db']->delete('DELETE FROM processed_messages WHERE queue_name = ?', [$this->settings->queue]);
            $this->app['db']->disconnect();
        }
        parent::tearDown();
    }

    private function environment(array $extra = []): array
    {
        return array_replace([
            'QUEUES' => $this->settings->queue,
            'QUEUE_NAME' => $this->settings->queue,
            'MAX_RETRIES' => '2',
            'MAX_QUEUE_LENGTH' => '10000',
            'RETRY_DELAY' => '10ms',
            'PROCESSING_DELAY' => '0s',
            'ACKNOWLEDGMENT_DELAY' => '0s',
            'CONCURRENCY' => '1',
            'PUBLISH_CONCURRENCY' => '2',
        ], $extra);
    }

    private function start(array $command, array $extra = []): Process
    {
        $process = new Process($command, dirname(__DIR__, 2), $this->environment($extra));
        $process->setTimeout(null);
        $process->start();
        $this->processes[] = $process;
        return $process;
    }

    private function worker(string $ackDelay = '0s'): Process
    {
        return $this->start([PHP_BINARY, 'artisan', 'queue:consume', '--single', '--no-ansi'], ['ACKNOWLEDGMENT_DELAY' => $ackDelay]);
    }

    private function publish(Envelope $message, ?string $queue = null): void
    {
        (new RabbitPublisher($this->broker, $this->settings))->publish($queue ?? $this->settings->queue, $message);
    }

    private function receiptCount(string $id): int
    {
        return (int) $this->app['db']->selectOne('SELECT count(*) AS n FROM processed_messages WHERE queue_name = ? AND message_id = ?', [$this->settings->queue, $id])->n;
    }

    private function eventually(callable $condition, string $description = 'condition', float $seconds = 20): void
    {
        $deadline = microtime(true) + $seconds;
        do {
            if ($condition()) { return; }
            usleep(20000);
        } while (microtime(true) < $deadline);
        $logs = '';
        foreach ($this->processes as $process) { $logs .= $process->getErrorOutput().$process->getOutput(); }
        self::fail($description." did not become true\n".$logs);
    }

    public function testGrpcPublicationAndCompetingDuplicateConsumers(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $server = $this->start(['rr', 'serve', '-c', '.rr.yaml'], ['GRPC_ADDRESS' => $address]);
        $this->eventually(function () use ($address): bool {
            $probe = new Process(['grpcurl', '-plaintext', '-max-time', '1', '-d', '{}', $address, 'grpc.health.v1.Health/Check']);
            $probe->run();
            return $probe->isSuccessful() && str_contains($probe->getOutput(), 'SERVING');
        }, 'gRPC readiness');
        $workers = [];
        for ($i = 0; $i < 4; $i++) { $workers[] = $this->worker(); }
        $clients = [];
        for ($i = 0; $i < 12; $i++) {
            $client = new Process(['grpcurl', '-plaintext', '-max-time', '10', '-import-path', 'api',
                '-proto', 'queue/v1/queue.proto', '-d', '@', $address, 'queue.v1.QueueService/Publish'], dirname(__DIR__, 2));
            $client->setInput(json_encode(['queue' => $this->settings->queue, 'message' => [
                'id' => 'concurrent', 'type' => 'lab.echo', 'payload' => base64_encode("\x00\xffhello"),
                'correlationId' => 'run', 'causationId' => 'parent',
            ]], JSON_THROW_ON_ERROR));
            $client->start();
            $clients[] = $client;
            $this->processes[] = $client;
        }
        foreach ($clients as $client) {
            self::assertSame(0, $client->wait(), $client->getErrorOutput());
            self::assertSame('concurrent', json_decode($client->getOutput(), true)['messageId']);
        }
        $this->eventually(function () use ($workers): bool {
            $acks = 0;
            foreach ($workers as $worker) { $acks += substr_count($worker->getErrorOutput(), 'message acknowledged'); }
            return $acks === 12;
        }, 'all duplicate deliveries acknowledged');
        self::assertSame(1, $this->receiptCount('concurrent'));
        $row = $this->app['db']->selectOne("SELECT encode(payload, 'hex') AS payload, correlation_id, causation_id FROM processed_messages WHERE queue_name = ? AND message_id = ?", [$this->settings->queue, 'concurrent']);
        self::assertSame(bin2hex("\x00\xffhello"), $row->payload);
        self::assertSame('run', $row->correlation_id);
        self::assertSame('parent', $row->causation_id);
    }

    public function testRetryBudgetAndDeadLetterMetadata(): void
    {
        $worker = $this->worker();
        $this->publish(new Envelope('poison', 'lab.fail'));
        $dead = null;
        $channel = $this->connection->channel();
        $this->eventually(function () use ($channel, &$dead): bool {
            $dead = $channel->basic_get($this->settings->queue.'.dead', false);
            return $dead !== null;
        }, 'poison message in DLQ');
        self::assertSame('poison', $dead->get('message_id'));
        self::assertArrayHasKey('x-death', $dead->get('application_headers')->getNativeData());
        self::assertSame(2, substr_count($worker->getErrorOutput(), 'message will be retried'));
        self::assertSame(0, $this->receiptCount('poison'));
        $dead->ack();
        $channel->close();
    }

    public function testUnroutablePublicationIsNotAccepted(): void
    {
        $this->expectException(Unroutable::class);
        $this->publish(new Envelope('unroutable', 'lab.echo'), 'missing-'.bin2hex(random_bytes(8)));
    }

    public function testConflictingIdGoesToDlqAndLeavesOriginalReceipt(): void
    {
        self::assertTrue($this->store->record($this->settings->queue, new Envelope('conflict', 'lab.echo', 'original')));
        self::assertFalse($this->store->record($this->settings->queue, new Envelope('conflict', 'lab.echo', 'original')));
        $this->worker();
        $this->publish(new Envelope('conflict', 'lab.echo', 'changed'));
        $channel = $this->connection->channel();
        $this->eventually(function () use ($channel): bool {
            $dead = $channel->basic_get($this->settings->queue.'.dead', false);
            if ($dead === null) { return false; }
            self::assertSame('conflict', $dead->get('message_id'));
            $dead->ack();
            return true;
        }, 'conflicting message in DLQ');
        self::assertSame(1, $this->receiptCount('conflict'));
        $channel->close();
    }

    public function testInvalidRawEnvelopeIsDeadLetteredWithoutEffect(): void
    {
        $this->worker();
        $this->publish(new Envelope('invalid', ''));
        $channel = $this->connection->channel();
        $this->eventually(function () use ($channel): bool {
            $dead = $channel->basic_get($this->settings->queue.'.dead', false);
            if ($dead === null) { return false; }
            self::assertSame('invalid', $dead->get('message_id'));
            $dead->ack();
            return true;
        });
        self::assertSame(0, $this->receiptCount('invalid'));
        $channel->close();
    }

    public function testProcessDeathAfterCommitDoesNotDuplicateEffect(): void
    {
        $first = $this->worker('30s');
        $this->publish(new Envelope('crash-window', 'lab.echo', 'survives'));
        $this->eventually(fn () => $this->receiptCount('crash-window') === 1 && str_contains($first->getErrorOutput(), 'message processed'), 'commit before ACK');
        $first->signal(SIGKILL);
        $first->wait();
        $replacement = $this->worker();
        $this->eventually(fn () => str_contains($replacement->getErrorOutput(), 'message acknowledged'), 'replacement ACK');
        self::assertStringContainsString('"redelivered":true', $replacement->getErrorOutput());
        self::assertStringContainsString('"new_receipt":false', $replacement->getErrorOutput());
        self::assertSame(1, $this->receiptCount('crash-window'));
    }

    public function testSupervisorStartsAndStopsAllConsumerChildren(): void
    {
        $parent = $this->start([PHP_BINARY, 'artisan', 'queue:consume', '--no-ansi'], ['CONCURRENCY' => '2']);
        $channel = $this->connection->channel();
        $this->eventually(function () use ($channel): bool {
            [, , $consumers] = $channel->queue_declare($this->settings->queue, true);
            return $consumers === 2;
        }, 'two supervised consumer subscriptions');
        $parent->signal(SIGTERM);
        $this->eventually(fn () => !$parent->isRunning(), 'supervisor shutdown');
        self::assertSame(0, $parent->getExitCode(), $parent->getErrorOutput());
        $this->eventually(function () use ($channel): bool {
            [, , $consumers] = $channel->queue_declare($this->settings->queue, true);
            return $consumers === 0;
        }, 'all child subscriptions closed');
        $channel->close();
    }

    public function testPeekDeadDoesNotRemoveTheMessage(): void
    {
        $this->worker();
        $this->publish(new Envelope('peek', 'lab.fail', "\x00\xff"));
        $channel = $this->connection->channel();
        $this->eventually(function () use ($channel): bool {
            [, $ready] = $channel->queue_declare($this->settings->queue.'.dead', true);
            return $ready === 1;
        }, 'dead letter ready for inspection');
        for ($i = 0; $i < 3; $i++) {
            $peek = $this->start([PHP_BINARY, 'artisan', 'queue:peek-dead', '--queue='.$this->settings->queue, '--no-ansi']);
            self::assertSame(0, $peek->wait(), $peek->getErrorOutput());
            $output = json_decode($peek->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::assertTrue($output['found']);
            self::assertSame('peek', $output['message_id']);
            self::assertSame(base64_encode("\x00\xff"), $output['payload']);
        }
        [, $ready] = $channel->queue_declare($this->settings->queue.'.dead', true);
        self::assertSame(1, $ready);
        $channel->close();
    }

    public function testOverflowRejectsPublication(): void
    {
        $name = $this->settings->queue.'-full';
        $settings = new Settings(array_replace(config('queue_lab'), ['queues' => $name, 'queue' => $name, 'max_queue_length' => 1]));
        $broker = new Broker($settings);
        $connection = $broker->connect();
        try {
            $broker->declare($connection);
            $publisher = new RabbitPublisher($broker, $settings);
            $publisher->publish($name, new Envelope('first', 'lab.echo'));
            // Quorum queues notify publishers asynchronously: a few messages can
            // overshoot the nominal limit before the rejection reaches a publisher.
            $rejected = false;
            for ($attempt = 2; $attempt <= 64; $attempt++) {
                try {
                    $publisher->publish($name, new Envelope('overflow-'.$attempt, 'lab.echo'));
                } catch (PublishRejected) {
                    $rejected = true;
                    break;
                }
                usleep(20000);
            }
            self::assertTrue($rejected, 'The full quorum queue must eventually reject publication');
        } finally {
            $channel = $connection->channel();
            $channel->queue_delete($name);
            $channel->queue_delete($name.'.dead');
            $connection->close();
        }
    }

    public function testReadyMessageSurvivesPublisherConnectionClosure(): void
    {
        $this->publish(new Envelope('persistent', 'lab.echo', 'waiting'));
        $channel = $this->connection->channel();
        $message = $channel->basic_get($this->settings->queue, false);
        self::assertNotNull($message);
        self::assertSame(AMQPMessage::DELIVERY_MODE_PERSISTENT, $message->get('delivery_mode'));
        $message->reject(true);
        $channel->close();
        $this->worker();
        $this->eventually(fn () => $this->receiptCount('persistent') === 1);
    }
}
