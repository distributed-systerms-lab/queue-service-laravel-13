<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Queue\Broker;
use App\Queue\Envelope;
use Illuminate\Console\Command;

final class Inspect extends Command
{
    protected $signature = 'queue:inspect {--queue=tasks}';
    protected $description = 'Report ready messages and consumers for a source queue and its DLQ';

    public function handle(Broker $broker): int
    {
        $queue = $this->option('queue');
        Envelope::validateQueue($queue);
        $connection = $broker->connect();
        try {
            $channel = $connection->channel();
            foreach ([$queue, $queue.'.dead'] as $name) {
                [$actual, $ready, $consumers] = $channel->queue_declare($name, true);
                $this->line(json_encode(['queue' => $actual, 'messages_ready' => $ready, 'consumers' => $consumers], JSON_THROW_ON_ERROR));
            }
        } finally { $connection->close(); }
        return self::SUCCESS;
    }
}
