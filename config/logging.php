<?php

return [
    'default' => 'stderr',
    'channels' => [
        'stderr' => [
            'driver' => 'monolog',
            'handler' => Monolog\Handler\StreamHandler::class,
            'handler_with' => ['stream' => 'php://stderr'],
            'formatter' => Monolog\Formatter\JsonFormatter::class,
            'level' => env('LOG_LEVEL', 'info'),
        ],
    ],
];
