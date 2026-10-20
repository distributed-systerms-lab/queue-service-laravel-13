<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Queue\Envelope;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EnvelopeTest extends TestCase
{
    public function testBinaryPayloadAndExactSizeLimitAreAccepted(): void
    {
        (new Envelope('id', 'lab.echo', "\x00\xff"))->validate();
        (new Envelope('id', 'lab.echo', str_repeat('x', Envelope::MAX_PAYLOAD)))->validate();
        $this->expectException(InvalidArgumentException::class);
        (new Envelope('id', 'lab.echo', str_repeat('x', Envelope::MAX_PAYLOAD + 1)))->validate();
    }

    public function testMetadataLimitUsesBytesAndChecksUtf8(): void
    {
        (new Envelope(str_repeat('a', 255), 'echo'))->validate();
        $this->expectException(InvalidArgumentException::class);
        (new Envelope(str_repeat('é', 128), 'echo'))->validate();
    }

    public function testInvalidUtf8MetadataIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Envelope('id', 'echo', '', "\xff"))->validate();
    }

    public function testFingerprintHasUnambiguousFieldBoundariesAndIgnoresId(): void
    {
        $first = new Envelope('one', 'ab', 'c', 'correlation', 'cause');
        $same = new Envelope('two', 'ab', 'c', 'correlation', 'cause');
        $different = new Envelope('one', 'a', 'bc', 'correlation', 'cause');
        self::assertSame(32, strlen($first->fingerprint()));
        self::assertSame($first->fingerprint(), $same->fingerprint());
        self::assertNotSame($first->fingerprint(), $different->fingerprint());
        // Explicit Go uint64 layout, independent of PHP's native endianness.
        $encoded = hex2bin('0000000000000002').'ab'.hex2bin('0000000000000001').'c'.
            hex2bin('000000000000000b').'correlation'.hex2bin('0000000000000005').'cause';
        self::assertSame(hash('sha256', $encoded, true), $first->fingerprint());
    }

    public static function queues(): iterable
    {
        yield ['tasks', true];
        yield ['A-0_ok', true];
        yield [str_repeat('a', 64), true];
        yield ['', false];
        yield ['tasks.dead', false];
        yield ['-tasks', false];
        yield [str_repeat('a', 65), false];
        yield ["tasks\n", false];
    }

    #[DataProvider('queues')]
    public function testQueueNames(string $queue, bool $valid): void
    {
        if (!$valid) { $this->expectException(InvalidArgumentException::class); }
        Envelope::validateQueue($queue);
        if ($valid) { self::assertTrue(true); }
    }
}
