<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Queue\Settings;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase
{
    public function testDefaultsAndQueueDeduplication(): void
    {
        $settings = new Settings(['queues' => ' tasks,mail,tasks ']);
        self::assertSame(['tasks', 'mail'], $settings->queues);
        self::assertSame(4, $settings->concurrency);
        self::assertSame(3, $settings->maxRetries);
        self::assertSame(5.0, $settings->operationTimeout);
        self::assertSame(0.1, Settings::duration('100ms'));
    }

    public static function invalid(): iterable
    {
        yield [['concurrency' => 0]];
        yield [['concurrency' => 129]];
        yield [['max_retries' => -1]];
        yield [['max_queue_length' => 'invalid']];
        yield [['queues' => 'tasks,,mail']];
        yield [['queue' => 'other']];
        yield [['retry_delay' => '61s']];
        yield [['operation_timeout' => '0s']];
        yield [['processing_delay' => '-1s']];
    }

    #[DataProvider('invalid')]
    public function testInvalidSettingsFailAtStartup(array $values): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Settings($values);
    }
}
