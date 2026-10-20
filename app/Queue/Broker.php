<?php

declare(strict_types=1);

namespace App\Queue;

use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Connection\AMQPConnectionConfig;
use PhpAmqpLib\Connection\AMQPConnectionFactory;
use PhpAmqpLib\Wire\AMQPTable;
use RuntimeException;
use Throwable;

final class Broker
{
    public function __construct(private readonly Settings $settings) {}

    public function connect(): AbstractConnection
    {
        $uri = parse_url($this->settings->amqpUrl);
        if (!$uri || ($uri['scheme'] ?? '') !== 'amqp' || empty($uri['host'])) {
            throw new RuntimeException('AMQP_URL must be a valid amqp:// URI');
        }
        $config = new AMQPConnectionConfig();
        // Socket IO supports distinct write/read timeouts with heartbeats.
        $config->setIoType(AMQPConnectionConfig::IO_TYPE_SOCKET);
        $config->setHost($uri['host']);
        $config->setPort($uri['port'] ?? 5672);
        $config->setUser(rawurldecode($uri['user'] ?? 'guest'));
        $config->setPassword(rawurldecode($uri['pass'] ?? 'guest'));
        $path = $uri['path'] ?? '/';
        $config->setVhost($path === '/' ? '/' : rawurldecode(substr($path, 1)));
        $config->setConnectionTimeout($this->settings->operationTimeout);
        $config->setReadTimeout(max(21, $this->settings->operationTimeout));
        $config->setWriteTimeout($this->settings->operationTimeout);
        $config->setChannelRPCTimeout($this->settings->operationTimeout);
        $config->setHeartbeat(10);
        $config->setKeepalive(true);
        try {
            return AMQPConnectionFactory::create($config);
        } catch (Throwable) {
            // Never include the URI or the library exception containing credentials.
            throw new RuntimeException('connect to RabbitMQ: check address, credentials and reachability');
        }
    }

    public function declare(AbstractConnection $connection): void
    {
        $channel = $connection->channel();
        try {
            foreach ($this->settings->queues as $name) {
                $channel->queue_declare($name.'.dead', false, true, false, false, false, new AMQPTable([
                    'x-queue-type' => 'quorum',
                    'x-delivery-limit' => -1,
                ]));
                $channel->queue_declare($name, false, true, false, false, false, new AMQPTable([
                    'x-queue-type' => 'quorum',
                    'x-delivery-limit' => $this->settings->maxRetries,
                    'x-dead-letter-exchange' => '',
                    'x-dead-letter-routing-key' => $name.'.dead',
                    'x-dead-letter-strategy' => 'at-least-once',
                    'x-overflow' => 'reject-publish',
                    'x-max-length' => $this->settings->maxQueueLength,
                ]));
            }
        } finally {
            $channel->close();
        }
    }
}
