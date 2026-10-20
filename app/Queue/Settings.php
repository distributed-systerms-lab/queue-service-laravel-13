<?php

declare(strict_types=1);

namespace App\Queue;

use InvalidArgumentException;

final readonly class Settings
{
    public array $queues;
    public string $queue;
    public string $amqpUrl;
    public int $concurrency;
    public int $maxRetries;
    public int $maxQueueLength;
    public float $operationTimeout;
    public float $shutdownTimeout;
    public float $retryDelay;
    public float $processingDelay;
    public float $acknowledgmentDelay;

    public function __construct(array $values)
    {
        $names = array_map('trim', explode(',', (string) ($values['queues'] ?? 'tasks')));
        foreach ($names as $name) {
            Envelope::validateQueue($name);
        }
        $this->queues = array_values(array_unique($names));
        $this->queue = (string) ($values['queue'] ?? 'tasks');
        if (!in_array($this->queue, $this->queues, true)) {
            throw new InvalidArgumentException('QUEUE_NAME is not in QUEUES');
        }
        $this->amqpUrl = (string) ($values['amqp_url'] ?? '');
        $this->concurrency = self::integer($values['concurrency'] ?? 4, 1, 128, 'CONCURRENCY');
        $this->maxRetries = self::integer($values['max_retries'] ?? 3, 0, 100, 'MAX_RETRIES');
        $this->maxQueueLength = self::integer($values['max_queue_length'] ?? 100000, 1, 10000000, 'MAX_QUEUE_LENGTH');
        $this->operationTimeout = self::duration($values['operation_timeout'] ?? '5s', false);
        $this->shutdownTimeout = self::duration($values['shutdown_timeout'] ?? '15s', false);
        $this->retryDelay = self::duration($values['retry_delay'] ?? '1s');
        $this->processingDelay = self::duration($values['processing_delay'] ?? '0s');
        $this->acknowledgmentDelay = self::duration($values['acknowledgment_delay'] ?? '0s');
    }

    private static function integer(mixed $value, int $min, int $max, string $name): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < $min || (int) $value > $max) {
            throw new InvalidArgumentException("$name must be an integer between $min and $max");
        }
        return (int) $value;
    }

    public static function duration(string $value, bool $allowZero = true): float
    {
        if (!preg_match('/\A([0-9]+(?:\.[0-9]+)?)(ms|s|m)\z/', $value, $parts)) {
            throw new InvalidArgumentException('duration must use ms, s or m');
        }
        $seconds = (float) $parts[1] * match ($parts[2]) { 'ms' => 0.001, 'm' => 60, default => 1 };
        if ($seconds > 60 || (!$allowZero && $seconds === 0.0)) {
            throw new InvalidArgumentException('duration must be at most 1m and greater than zero where required');
        }
        return $seconds;
    }
}
