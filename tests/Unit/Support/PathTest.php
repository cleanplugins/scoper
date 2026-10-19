<?php

declare(strict_types=1);

namespace CleanPlugins\Scoper\Tests\Unit\Support;

use CleanPlugins\Scoper\Support\Path;
use CleanPlugins\Scoper\Tests\TestCase;

final class PathTest extends TestCase
{
    public function testComputesRelativePathsForACustomVendorDirectory(): void
    {
        $vendor = 'C:/project/build/lib/vendor';

        self::assertSame('../../includes/functions.php', Path::relative($vendor, 'C:/project/build/includes/functions.php'));
        self::assertSame('scssphp/scssphp/src/Compiler.php', Path::relative($vendor, 'C:/project/build/lib/vendor/scssphp/scssphp/src/Compiler.php'));
        self::assertSame('lib/vendor/scssphp/scssphp', Path::join('lib/vendor/composer', '../scssphp/scssphp'));
    }

    public function testNormalizesWindowsAndUnixPaths(): void
    {
        self::assertSame('C:/project/src/File.php', Path::normalize('C:\\project\\src\\.\\File.php'));
        self::assertSame('/var/www/src', Path::normalize('/var/www/../www/src'));
        self::assertSame('src/File.php', Path::normalize('src/sub/../File.php'));
        self::assertSame('../src', Path::normalize('../src'));
    }

    public function testDetectsAbsolutePaths(): void
    {
        self::assertTrue(Path::isAbsolute('C:\\project'));
        self::assertTrue(Path::isAbsolute('C:/project'));
        self::assertTrue(Path::isAbsolute('/project'));
        self::assertFalse(Path::isAbsolute('project'));
        self::assertFalse(Path::isAbsolute('../project'));
    }

    public function testDetectsPathsInsideAnotherPath(): void
    {
        self::assertTrue(Path::isInside('C:/project/build/file.php', 'C:/project/build'));
        self::assertTrue(Path::isInside('C:/project/build', 'C:/project/build'));
        self::assertFalse(Path::isInside('C:/project/build-other/file.php', 'C:/project/build'));
    }
}
