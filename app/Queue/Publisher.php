<?php

declare(strict_types=1);

namespace App\Queue;

interface Publisher
{
    public function publish(string $queue, Envelope $message): void;
}
