<?php

declare(strict_types=1);

namespace App\Queue;

use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

final class RabbitPublisher implements Publisher
{
    public function __construct(
        private readonly Broker $broker,
        private readonly Settings $settings,
    ) {}

    public function publish(string $queue, Envelope $message): void
    {
        // One channel per call: a late confirm cannot satisfy a later request.
        $connection = $this->broker->connect();
        $channel = null;
        try {
            $channel = $connection->channel();
        } catch (Throwable $error) {
            try { $connection->close(); } catch (Throwable) {}
            throw $error;
        }
        try {
            $returned = false;
            $rejected = false;
            $channel->set_return_listener(function () use (&$returned): void { $returned = true; });
            $channel->set_nack_handler(function () use (&$rejected): void { $rejected = true; });
            $channel->confirm_select();
            $channel->basic_publish(new AMQPMessage($message->payload, [
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'content_type' => 'application/octet-stream',
                'message_id' => $message->id,
                'type' => $message->type,
                'correlation_id' => $message->correlationId,
                'application_headers' => new AMQPTable(['causation-id' => $message->causationId]),
                'timestamp' => time(),
            ]), '', $queue, true);
            $channel->wait_for_pending_acks_returns($this->settings->operationTimeout);
            if ($returned) {
                throw new Unroutable('message could not be routed');
            }
            if ($rejected) {
                throw new PublishRejected('broker rejected the message');
            }
        } finally {
            try { $channel->close(); } catch (Throwable) {}
            try { $connection->close(); } catch (Throwable) {}
        }
    }
}
