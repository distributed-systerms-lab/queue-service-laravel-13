<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

final class Health extends Command
{
    protected $signature = 'queue:health {--address=127.0.0.1:50051}';
    protected $description = 'Check the standard gRPC health endpoint';

    public function handle(): int
    {
        $client = new Process(['grpcurl', '-plaintext', '-max-time', '2', '-d', '{}',
            $this->option('address'), 'grpc.health.v1.Health/Check']);
        $client->setTimeout(3);
        $client->run();
        $response = json_decode($client->getOutput(), true);
        return $client->isSuccessful() && ($response['status'] ?? null) === 'SERVING' ? self::SUCCESS : self::FAILURE;
    }
}
