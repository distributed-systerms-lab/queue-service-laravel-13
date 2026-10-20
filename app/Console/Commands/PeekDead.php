<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Queue\Broker;
use App\Queue\Envelope;
use Illuminate\Console\Command;

final class PeekDead extends Command
{
    protected $signature = 'queue:peek-dead {--queue=tasks}';
    protected $description = 'Print one dead letter as JSON, then requeue it without deleting it';

    public function handle(Broker $broker): int
    {
        $queue = $this->option('queue');
        Envelope::validateQueue($queue);
        $connection = $broker->connect();
        try {
            $delivery = $connection->channel()->basic_get($queue.'.dead', false);
            if ($delivery === null) {
                $this->line('{"found":false}');
            } else {
                try {
                    $properties = $delivery->get_properties();
                    $this->line(json_encode([
                        'found' => true, 'message_id' => $properties['message_id'] ?? '',
                        'type' => $properties['type'] ?? '', 'payload' => base64_encode($delivery->getBody()),
                        'correlation_id' => $properties['correlation_id'] ?? '',
                        'headers' => isset($properties['application_headers']) ? $properties['application_headers']->getNativeData() : [],
                    ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
                } finally { $delivery->nack(false, true); }
            }
        } finally { $connection->close(); }
        return self::SUCCESS;
    }
}
