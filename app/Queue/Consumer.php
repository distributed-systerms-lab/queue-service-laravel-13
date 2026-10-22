<?php

declare(strict_types=1);

namespace App\Queue;

use PhpAmqpLib\Exception\AMQPTimeoutException;
use Psr\Log\LoggerInterface;
use Throwable;

final class Consumer
{
    public function __construct(
        private readonly Broker $broker,
        private readonly Settings $settings,
        private readonly ReceiptStore $store,
        private readonly DeliveryHandler $handler,
        private readonly LoggerInterface $logger,
    ) {}

    public function run(StopToken $stop): void
    {
        // Open connections only in the child, after process creation.
        $connection = $this->broker->connect();
        try {
            $this->broker->declare($connection);
            $this->store->migrate();
            $channel = $connection->channel();
            $channel->basic_qos(0, 1, false);
            $channel->basic_consume($this->settings->queue, '', false, false, false, false,
                function ($message) use ($stop, $connection): void {
                    $this->handler->handle($message, $stop, fn () => $connection->checkHeartBeat());
                });
            $this->logger->info('consumer started', ['queue' => $this->settings->queue, 'pid' => getmypid()]);
            while (!$stop->requested && $channel->is_consuming()) {
                try { $channel->wait(null, false, 1); } catch (AMQPTimeoutException) {}
            }
        } catch (Stopping) {
            // Closing the channel leaves unfinished deliveries for redelivery.
        } finally {
            try { $connection->close(); } catch (Throwable) {}
        }
    }
}
