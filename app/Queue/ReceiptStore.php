<?php

declare(strict_types=1);

namespace App\Queue;

use Illuminate\Database\ConnectionInterface;

final class ReceiptStore implements Recorder
{
    public function __construct(
        private readonly ConnectionInterface $database,
        private readonly Settings $settings,
    ) {}

    public function migrate(): void
    {
        $this->database->transaction(function (): void {
            $this->timeout();
            $this->database->select('SELECT pg_advisory_xact_lock(7264358101)');
            $this->database->unprepared(file_get_contents(base_path('database/schema/receipts.sql')));
        });
    }

    public function record(string $queue, Envelope $message): bool
    {
        return $this->database->transaction(function () use ($queue, $message): bool {
            $this->timeout();
            $fingerprint = bin2hex($message->fingerprint());
            $inserted = $this->database->affectingStatement(
                "INSERT INTO processed_messages
                (queue_name, message_id, fingerprint, message_type, payload, correlation_id, causation_id)
                VALUES (?, ?, decode(?, 'hex'), ?, decode(?, 'hex'), ?, ?)
                ON CONFLICT (queue_name, message_id) DO NOTHING",
                [$queue, $message->id, $fingerprint, $message->type, bin2hex($message->payload), $message->correlationId, $message->causationId],
            );
            if ($inserted === 1) {
                return true;
            }
            $stored = $this->database->selectOne(
                "SELECT encode(fingerprint, 'hex') AS fingerprint FROM processed_messages WHERE queue_name = ? AND message_id = ?",
                [$queue, $message->id],
            );
            if ($stored === null || !hash_equals($fingerprint, $stored->fingerprint)) {
                throw new IdentityConflict('message ID already belongs to a different envelope');
            }
            return false;
        });
    }

    private function timeout(): void
    {
        $this->database->select("SELECT set_config('statement_timeout', ?, true)", [(string) max(1, (int) ceil($this->settings->operationTimeout * 1000))]);
    }
}
