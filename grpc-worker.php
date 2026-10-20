<?php

declare(strict_types=1);

use App\Grpc\QueueService;
use App\Grpc\QueueServiceInterface;
use Illuminate\Contracts\Console\Kernel;
use Spiral\RoadRunner\GRPC\Invoker;
use Spiral\RoadRunner\GRPC\Server;
use Spiral\RoadRunner\Worker;

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Boot Laravel once per PHP process. No HTTP request/session state is retained.
$server = new Server(new Invoker(), ['debug' => false]);
$server->registerService(QueueServiceInterface::class, $app->make(QueueService::class));
$server->serve(Worker::create());
