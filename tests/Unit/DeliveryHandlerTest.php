<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Queue\DeliveryHandler;
use App\Queue\Envelope;
use App\Queue\IdentityConflict;
use App\Queue\Recorder;
use App\Queue\Settings;
use App\Queue\StopToken;
use App\Queue\Stopping;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;

final class DeliveryHandlerTest extends TestCase
{
    public static function outcomes(): iterable
    {
        yield 'new effect' => ['lab.echo', 0, null, true, 'ack', false];
        yield 'duplicate' => ['lab.echo', 0, null, false, 'ack', false];
        yield 'transient error' => ['lab.echo', 0, new RuntimeException('offline'), false, 'reject', true];
        yield 'exhausted' => ['lab.fail', 3, null, false, 'nack', false];
        yield 'conflicting ID' => ['lab.echo', 0, new IdentityConflict(), false, 'nack', false];
        yield 'invalid envelope' => ['', 0, null, false, 'nack', false];
    }

    #[DataProvider('outcomes')]
    public function testDeliveryOutcome(string $type, int $count, ?Throwable $failure, bool $inserted, string $action, bool $requeue): void
    {
        $recorder = new class($failure, $inserted) implements Recorder {
            public function __construct(private ?Throwable $failure, private bool $inserted) {}
            public function record(string $queue, Envelope $message): bool {
                if ($this->failure !== null) { throw $this->failure; }
                return $this->inserted;
            }
        };
        $message = $this->getMockBuilder(AMQPMessage::class)
            ->setConstructorArgs(['body', ['message_id' => 'example', 'type' => $type,
                'application_headers' => new AMQPTable(['x-delivery-count' => $count])]])
            ->onlyMethods(['ack', 'nack', 'reject', 'isRedelivered'])->getMock();
        foreach (['ack', 'nack', 'reject'] as $method) {
            $expectation = $message->expects($method === $action ? self::once() : self::never())->method($method);
            if ($method === $action && $method === 'reject') { $expectation->with($requeue); }
            if ($method === $action && $method === 'nack') { $expectation->with(false, $requeue); }
        }
        (new DeliveryHandler($recorder, new Settings(['retry_delay' => '0s']), new NullLogger()))->handle($message, new StopToken());
    }

    public function testStopAfterCommitLeavesDeliveryUnacknowledged(): void
    {
        $stop = new StopToken();
        $recorder = new class($stop) implements Recorder {
            public function __construct(private StopToken $stop) {}
            public function record(string $queue, Envelope $message): bool {
                $this->stop->requested = true;
                return true;
            }
        };
        $message = $this->getMockBuilder(AMQPMessage::class)
            ->setConstructorArgs(['', ['message_id' => 'id', 'type' => 'lab.echo']])
            ->onlyMethods(['ack', 'nack', 'reject', 'isRedelivered'])->getMock();
        $message->expects(self::never())->method('ack');
        $message->expects(self::never())->method('nack');
        $message->expects(self::never())->method('reject');
        $this->expectException(Stopping::class);
        (new DeliveryHandler($recorder, new Settings([]), new NullLogger()))->handle($message, $stop);
    }
}
