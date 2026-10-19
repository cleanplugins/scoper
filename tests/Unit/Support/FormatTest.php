<?php

declare(strict_types=1);

namespace CleanPlugins\Scoper\Tests\Unit\Support;

use CleanPlugins\Scoper\Support\Format;
use CleanPlugins\Scoper\Tests\TestCase;

final class FormatTest extends TestCase
{
    public function testFormatsCountsAndBytes(): void
    {
        self::assertSame('1,234', Format::count(1234));
        self::assertSame('0 B', Format::bytes(0));
        self::assertSame('1.0 KB', Format::bytes(1024));
        self::assertSame('1.5 MB', Format::bytes(1024 * 1024 + 512 * 1024));
    }

    public function testFormatsDurations(): void
    {
        self::assertSame('0.00s', Format::duration(0));
        self::assertSame('4.28s', Format::duration(4280));
        self::assertSame('1m 05s', Format::duration(65000));
    }

    public function testReadsBooleanEnvironmentValues(): void
    {
        self::assertTrue(Format::enabled('1'));
        self::assertTrue(Format::enabled('true'));
        self::assertTrue(Format::enabled('yes'));
        self::assertFalse(Format::enabled('0'));
        self::assertFalse(Format::enabled('false'));
        self::assertFalse(Format::enabled(''));
        self::assertFalse(Format::enabled(null));
    }
}
