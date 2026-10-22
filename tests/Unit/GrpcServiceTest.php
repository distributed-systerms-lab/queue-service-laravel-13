<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Grpc\QueueService;
use App\Queue\Envelope;
use App\Queue\Publisher;
use App\Queue\PublishRejected;
use App\Queue\Settings;
use App\Queue\Unroutable;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Queue\V1\Message;
use Queue\V1\PublishRequest;
use Spiral\RoadRunner\GRPC\ContextInterface;
use Spiral\RoadRunner\GRPC\Exception\GRPCException;
use Spiral\RoadRunner\GRPC\StatusCode;
use Throwable;

final class GrpcServiceTest extends TestCase
{
    private function publisher(?Throwable $failure = null): Publisher
    {
        return new class($failure) implements Publisher {
            public int $calls = 0;
            public function __construct(private ?Throwable $failure) {}
            public function publish(string $queue, Envelope $message): void {
                $this->calls++;
                if ($this->failure !== null) { throw $this->failure; }
            }
        };
    }

    public function testValidationHappensBeforeBrokerContact(): void
    {
        $publisher = $this->publisher();
        $service = new QueueService($publisher, new Settings([]), new NullLogger());
        try {
            $service->Publish($this->createStub(ContextInterface::class), new PublishRequest());
            self::fail('expected validation failure');
        } catch (GRPCException $error) {
            self::assertSame(StatusCode::INVALID_ARGUMENT, $error->getCode());
            self::assertSame(0, $publisher->calls);
        }
    }

    public function testSuccessfulPublishPreservesId(): void
    {
        $publisher = $this->publisher();
        $service = new QueueService($publisher, new Settings([]), new NullLogger());
        $request = (new PublishRequest())->setQueue('tasks')->setMessage((new Message())->setId('stable-id')->setType('lab.echo')->setPayload("\x00\xff"));
        self::assertSame('stable-id', $service->Publish($this->createStub(ContextInterface::class), $request)->getMessageId());
        self::assertSame(1, $publisher->calls);
    }

    public static function errors(): iterable
    {
        yield [new PublishRejected(), StatusCode::RESOURCE_EXHAUSTED];
        yield [new Unroutable(), StatusCode::UNAVAILABLE];
        yield [new AMQPTimeoutException(), StatusCode::DEADLINE_EXCEEDED];
    }

    #[DataProvider('errors')]
    public function testBrokerErrorsMapToGrpcStatus(Throwable $failure, int $status): void
    {
        $service = new QueueService($this->publisher($failure), new Settings([]), new NullLogger());
        $request = (new PublishRequest())->setQueue('tasks')->setMessage((new Message())->setId('id')->setType('lab.echo'));
        $this->expectException(GRPCException::class);
        $this->expectExceptionCode($status);
        $service->Publish($this->createStub(ContextInterface::class), $request);
    }
}
