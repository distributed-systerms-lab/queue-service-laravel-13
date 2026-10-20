<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Queue\Consumer;
use App\Queue\Settings;
use App\Queue\StopToken;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Throwable;

final class Consume extends Command
{
    protected $signature = 'queue:consume {--single : Run one child consumer directly}';
    protected $description = 'Run competing AMQP consumers with manual acknowledgments';

    public function handle(Settings $settings): int
    {
        $stop = new StopToken();
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn () => $stop->requested = true);
        pcntl_signal(SIGINT, fn () => $stop->requested = true);
        if ($this->option('single')) {
            app(Consumer::class)->run($stop);
            return self::SUCCESS;
        }
        $processes = [];
        $status = self::SUCCESS;
        try {
            for ($index = 0; $index < $settings->concurrency; $index++) {
                $child = new Process([PHP_BINARY, base_path('artisan'), 'queue:consume', '--single', '--no-ansi'], base_path());
                $child->setTimeout(null);
                $child->start(static function (string $type, string $buffer): void {
                    fwrite($type === Process::ERR ? STDERR : STDOUT, $buffer);
                });
                $processes[] = $child;
            }
            while (!$stop->requested) {
                foreach ($processes as $child) {
                    if (!$child->isRunning()) {
                        $status = self::FAILURE;
                        $stop->requested = true;
                        break;
                    }
                }
                if (!$stop->requested) { usleep(100000); }
            }
        } finally {
            foreach ($processes as $child) {
                if ($child->isRunning()) { $child->signal(SIGTERM); }
            }
            $deadline = microtime(true) + $settings->shutdownTimeout;
            do {
                $running = false;
                foreach ($processes as $child) { $running = $child->isRunning() || $running; }
                if (!$running) { break; }
                usleep(50000);
            } while (microtime(true) < $deadline);
            foreach ($processes as $child) {
                try {
                    if ($child->isRunning()) { $child->signal(SIGKILL); }
                    $child->wait();
                } catch (Throwable) { $status = self::FAILURE; }
            }
        }
        return $status;
    }
}
