<?php

return [
    'amqp_url' => env('AMQP_URL', ''),
    'queues' => env('QUEUES', 'tasks'),
    'queue' => env('QUEUE_NAME', 'tasks'),
    'concurrency' => env('CONCURRENCY', 4),
    'max_retries' => env('MAX_RETRIES', 3),
    'max_queue_length' => env('MAX_QUEUE_LENGTH', 100000),
    'operation_timeout' => env('OPERATION_TIMEOUT', '5s'),
    'shutdown_timeout' => env('SHUTDOWN_TIMEOUT', '15s'),
    'retry_delay' => env('RETRY_DELAY', '1s'),
    'processing_delay' => env('PROCESSING_DELAY', '0s'),
    'acknowledgment_delay' => env('ACKNOWLEDGMENT_DELAY', '0s'),
];
