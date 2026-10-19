<?php

declare(strict_types=1);

namespace CleanPlugins\Scoper\Tests\Unit\Config;

use CleanPlugins\Scoper\Config\Glob;
use CleanPlugins\Scoper\Tests\TestCase;

final class GlobTest extends TestCase
{
    public function testMatchesDocumentedGlobSemantics(): void
    {
        self::assertTrue(Glob::isIgnored('vendor/symfony/polyfill-ctype/README.md', ['vendor/**/*.md']));
        self::assertFalse(Glob::isIgnored('vendor/symfony/polyfill-ctype/LICENSE', ['vendor/**/*.md']));
        self::assertTrue(Glob::matchesName('LICENSE.md', 'license*'));
        self::assertTrue(Glob::isIgnored('vendor/a/b/bin/tool', ['vendor/*/*/bin']));
    }

    public function testDirectoryPatternCoversItsContents(): void
    {
        self::assertTrue(Glob::isIgnored('vendor/acme/lib', ['vendor/acme/lib']));
        self::assertTrue(Glob::isIgnored('vendor/acme/lib/src/File.php', ['vendor/acme/lib']));
        self::assertFalse(Glob::isIgnored('vendor/acme/library/File.php', ['vendor/acme/lib']));
    }

    public function testDoubleStarSlashMatchesZeroOrMoreSegments(): void
    {
        self::assertTrue(Glob::isIgnored('vendor/README.md', ['vendor/**/*.md']));
        self::assertTrue(Glob::isIgnored('vendor/a/b/README.md', ['vendor/**/*.md']));
        self::assertTrue(Glob::isIgnored('vendor/a/b/c/tool', ['vendor/**/tool']));
    }

    public function testExclusionsMatchFileNamesOnly(): void
    {
        self::assertTrue(Glob::isExcluded(['license*'], 'vendor/acme/lib/LICENSE'));
        self::assertTrue(Glob::isExcluded(['license*'], 'vendor/acme/lib/LICENSE.md'));
        self::assertFalse(Glob::isExcluded(['license*'], 'vendor/acme/lib/README.md'));
    }

    public function testPackageWildcardsMatchNames(): void
    {
        self::assertSame(['symfony/polyfill-ctype', 'symfony/polyfill-mbstring'], Glob::packagesMatching(['acme/lib', 'symfony/polyfill-ctype', 'symfony/polyfill-mbstring'], 'symfony/polyfill-*'));
    }
}
