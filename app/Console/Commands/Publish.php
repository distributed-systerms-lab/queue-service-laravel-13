<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

final class Publish extends Command
{
    protected $signature = 'queue:publish
        {--address=127.0.0.1:50051}
        {--queue=tasks}
        {--id=}
        {--type=lab.echo}
        {--payload=}
        {--correlation-id=}
        {--causation-id=}';
    protected $description = 'Publish through the public gRPC API, not directly to the broker';

    public function handle(): int
    {
        if ($this->option('id') === null || $this->option('id') === '') {
            $this->error('--id is required; retain it when retrying an uncertain result');
            return self::FAILURE;
        }
        $request = json_encode([
            'queue' => $this->option('queue'),
            'message' => [
                'id' => $this->option('id'), 'type' => $this->option('type'),
                'payload' => base64_encode($this->option('payload') ?? ''),
                'correlationId' => $this->option('correlation-id') ?? '',
                'causationId' => $this->option('causation-id') ?? '',
            ],
        ], JSON_THROW_ON_ERROR);
        $client = new Process([
            'grpcurl', '-plaintext', '-max-time', '10',
            '-import-path', base_path('api'), '-proto', 'queue/v1/queue.proto',
            '-d', '@', $this->option('address'), 'queue.v1.QueueService/Publish',
        ]);
        $client->setInput($request);
        $client->setTimeout(15);
        $client->run(fn (string $type, string $buffer) => fwrite($type === Process::ERR ? STDERR : STDOUT, $buffer));
        return $client->isSuccessful() ? self::SUCCESS : self::FAILURE;
    }
}
