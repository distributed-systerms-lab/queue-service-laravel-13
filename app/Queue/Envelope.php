<?php

declare(strict_types=1);

namespace App\Queue;

use InvalidArgumentException;

final readonly class Envelope
{
    public const MAX_PAYLOAD = 1048576;

    public function __construct(
        public string $id,
        public string $type,
        public string $payload = '',
        public string $correlationId = '',
        public string $causationId = '',
    ) {}

    public static function validateQueue(string $queue): void
    {
        if (!preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}\z/', $queue)) {
            throw new InvalidArgumentException('queue must contain 1 to 64 letters, digits, underscores or hyphens and start with a letter or digit');
        }
    }

    public function validate(): void
    {
        foreach (['id' => $this->id, 'type' => $this->type, 'correlation_id' => $this->correlationId, 'causation_id' => $this->causationId] as $name => $value) {
            if (($name === 'id' || $name === 'type') && $value === '') {
                throw new InvalidArgumentException("$name is required");
            }
            if (strlen($value) > 255 || !mb_check_encoding($value, 'UTF-8')) {
                throw new InvalidArgumentException("$name must be valid UTF-8 and at most 255 bytes");
            }
        }
        if (strlen($this->payload) > self::MAX_PAYLOAD) {
            throw new InvalidArgumentException('payload exceeds '.self::MAX_PAYLOAD.' bytes');
        }
    }

    // Same length-prefixed SHA-256 input as Go: four uint64 big-endian lengths.
    public function fingerprint(): string
    {
        $hash = hash_init('sha256');
        foreach ([$this->type, $this->payload, $this->correlationId, $this->causationId] as $field) {
            hash_update($hash, pack('N2', 0, strlen($field)).$field);
        }
        return hash_final($hash, true);
    }
}
