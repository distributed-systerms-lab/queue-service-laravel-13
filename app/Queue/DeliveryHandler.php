<?php

declare(strict_types=1);

namespace App\Queue;

use InvalidArgumentException;
use PhpAmqpLib\Message\AMQPMessage;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class DeliveryHandler
{
    public function __construct(
        private readonly Recorder $recorder,
        private readonly Settings $settings,
        private readonly LoggerInterface $logger,
    ) {}

    public function handle(AMQPMessage $delivery, StopToken $stop, ?callable $heartbeat = null): void
    {
        $properties = $delivery->get_properties();
        $headers = isset($properties['application_headers']) ? $properties['application_headers']->getNativeData() : [];
        $message = new Envelope(
            (string) ($properties['message_id'] ?? ''),
            (string) ($properties['type'] ?? ''),
            $delivery->getBody(),
            (string) ($properties['correlation_id'] ?? ''),
            is_string($headers['causation-id'] ?? null) ? $headers['causation-id'] : '',
        );
        $retries = is_int($headers['x-delivery-count'] ?? null) ? $headers['x-delivery-count'] : 0;
        $context = [
            'message_id' => $message->id, 'queue' => $this->settings->queue, 'type' => $message->type,
            'correlation_id' => $message->correlationId, 'causation_id' => $message->causationId,
            'redelivered' => $delivery->isRedelivered(), 'retry_count' => $retries,
        ];
        $stop->check();
        try {
            $message->validate();
        } catch (InvalidArgumentException $error) {
            $this->logger->warning('invalid message dead-lettered', $context);
            $delivery->nack(false, false);
            return;
        }
        $stop->wait($this->settings->processingDelay, $heartbeat);
        try {
            if ($message->type === 'lab.fail') {
                throw new RuntimeException('requested failure injection');
            }
            $inserted = $this->recorder->record($this->settings->queue, $message);
        } catch (Throwable $error) {
            $stop->check();
            // Do not log database exception text: it can contain payload or credentials.
            if ($error instanceof IdentityConflict || $retries >= $this->settings->maxRetries) {
                $this->logger->warning('message dead-lettered', $context + ['failure' => $error::class]);
                $delivery->nack(false, false);
                return;
            }
            $this->logger->warning('message will be retried', $context + ['failure' => $error::class]);
            $stop->wait($this->settings->retryDelay, $heartbeat);
            // RabbitMQ 4.3 counts reject(requeue), unlike nack(requeue).
            $delivery->reject(true);
            return;
        }
        $stop->check();
        $this->logger->info('message processed', $context + ['new_receipt' => $inserted]);
        $stop->wait($this->settings->acknowledgmentDelay, $heartbeat);
        $delivery->ack();
        $this->logger->info('message acknowledged', $context);
    }
}
