<?php

declare(strict_types=1);

namespace App\Queue;

interface Recorder
{
    public function record(string $queue, Envelope $message): bool;
}
