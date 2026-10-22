<?php

declare(strict_types=1);

namespace App\Queue;

final class StopToken
{
    public bool $requested = false;

    public function check(): void
    {
        if ($this->requested) {
            throw new Stopping('consumer stopping');
        }
    }

    public function wait(float $seconds, ?callable $tick = null): void
    {
        $deadline = microtime(true) + $seconds;
        do {
            $this->check();
            if ($tick !== null) { $tick(); }
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) { return; }
            usleep((int) min(100000, max(1, $remaining * 1000000)));
        } while (true);
    }
}
