<?php

declare(strict_types=1);

namespace App\Providers;

use App\Queue\Broker;
use App\Queue\Publisher;
use App\Queue\RabbitPublisher;
use App\Queue\ReceiptStore;
use App\Queue\Recorder;
use App\Queue\Settings;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Settings::class, fn () => new Settings(config('queue_lab')));
        $this->app->singleton(Publisher::class, function ($app): Publisher {
            $broker = $app->make(Broker::class);
            $connection = $broker->connect();
            try { $broker->declare($connection); } finally { $connection->close(); }
            return new RabbitPublisher($broker, $app->make(Settings::class));
        });
        $this->app->singleton(ReceiptStore::class, fn ($app) => new ReceiptStore($app['db']->connection(), $app->make(Settings::class)));
        $this->app->alias(ReceiptStore::class, Recorder::class);
    }
}
