<?php

declare(strict_types=1);

namespace App\Grpc;

use App\Queue\Envelope;
use App\Queue\Publisher;
use App\Queue\PublishRejected;
use App\Queue\Settings;
use InvalidArgumentException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use Psr\Log\LoggerInterface;
use Queue\V1\PublishRequest;
use Queue\V1\PublishResponse;
use Spiral\RoadRunner\GRPC\ContextInterface;
use Spiral\RoadRunner\GRPC\Exception\GRPCException;
use Spiral\RoadRunner\GRPC\StatusCode;
use Throwable;

final class QueueService implements QueueServiceInterface
{
    public function __construct(
        private readonly Publisher $publisher,
        private readonly Settings $settings,
        private readonly LoggerInterface $logger,
    ) {}

    public function Publish(ContextInterface $ctx, PublishRequest $in): PublishResponse
    {
        try {
            if (!$in->hasMessage()) {
                throw new InvalidArgumentException('message is required');
            }
            if (!in_array($in->getQueue(), $this->settings->queues, true)) {
                throw new InvalidArgumentException('queue is not configured');
            }
            $input = $in->getMessage();
            $message = new Envelope($input->getId(), $input->getType(), $input->getPayload(), $input->getCorrelationId(), $input->getCausationId());
            $message->validate();
        } catch (InvalidArgumentException $error) {
            throw new GRPCException($error->getMessage(), StatusCode::INVALID_ARGUMENT);
        }
        try {
            $this->publisher->publish($in->getQueue(), $message);
        } catch (AMQPTimeoutException) {
            throw new GRPCException('broker confirmation timed out; retry with the same message ID', StatusCode::DEADLINE_EXCEEDED);
        } catch (PublishRejected) {
            throw new GRPCException('broker rejected the message', StatusCode::RESOURCE_EXHAUSTED);
        } catch (Throwable $error) {
            $this->logger->warning('publication failed', ['queue' => $in->getQueue(), 'message_id' => $message->id, 'failure' => $error::class]);
            throw new GRPCException('broker unavailable; retry with the same message ID', StatusCode::UNAVAILABLE);
        }
        $this->logger->info('message accepted', ['queue' => $in->getQueue(), 'message_id' => $message->id,
            'correlation_id' => $message->correlationId, 'causation_id' => $message->causationId]);
        return (new PublishResponse())->setMessageId($message->id);
    }
}
