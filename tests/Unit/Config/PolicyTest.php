<?php

declare(strict_types=1);

namespace CleanPlugins\Scoper\Tests\Unit\Config;

use CleanPlugins\Scoper\Config\Policy;
use CleanPlugins\Scoper\Exception\ScoperException;
use CleanPlugins\Scoper\Tests\TestCase;

final class PolicyTest extends TestCase
{
    public function testParsesIgnoreRemoveAndOverrideEntries(): void
    {
        $policy = Policy::from(
            [
                'ignore' => [
                    [
                        'type' => 'package',
                        'value' => 'symfony/polyfill-*',
                        'reason' => 'Keep extension fallbacks global.',
                    ],
                    [
                        'type' => 'string',
                        'value' => 'Prefix_',
                        'package' => 'acme/library',
                        'reason' => 'Introspection literal.',
                    ],
                    [
                        'type' => 'path',
                        'value' => 'vendor/acme/library/docs',
                        'reason' => 'Documentation is not required.',
                    ],
                    [
                        'type' => 'namespace',
                        'value' => 'Acme\\Protected',
                        'reason' => 'Project-owned namespace.',
                    ],
                    [
                        'type' => 'global',
                        'value' => 'Acme_Global',
                        'reason' => 'Globally provided symbol.',
                    ],
                ],
                'remove' => [
                    [
                        'type' => 'path',
                        'value' => 'vendor/**/*.md',
                        'exclude' => 'license*',
                        'reason' => 'Drop documentation but keep licences.',
                    ],
                    [
                        'type' => 'package',
                        'value' => 'acme/removed',
                        'reason' => 'Unused package.',
                    ],
                ],
                'override' => [
                    [
                        'type' => 'namespace',
                        'value' => 'Acme\\Forced',
                        'reason' => 'Force a namespace rewrite.',
                    ],
                    [
                        'type' => 'global',
                        'value' => 'Acme_Forced',
                        'reason' => 'Force a global rewrite.',
                    ],
                ],
            ],
            'vendor',
            'dist',
        );

        self::assertTrue($policy->isIgnored('vendor/acme/library/docs/readme.md'));
        self::assertFalse($policy->isIgnored('vendor/acme/library/src/File.php'));
        self::assertTrue($policy->isRemoved('vendor/acme/library/README.md'));
        self::assertFalse($policy->isRemoved('vendor/acme/library/LICENSE.md'));
        self::assertTrue($policy->isRemoved('vendor/acme/removed/src/File.php'));
        self::assertSame('ignore', $policy->stringRule('ignore', 'acme/library', 'Prefix_')['action'] ?? null);
        self::assertSame('Introspection literal.', $policy->stringRule('ignore', 'acme/library', 'Prefix_')['reason'] ?? null);
        self::assertNull($policy->stringRule('ignore', 'other/library', 'Prefix_'));
        self::assertArrayHasKey('Acme\\Protected', $policy->ignoredNamespaces);
        self::assertArrayHasKey('Acme_Global', $policy->ignoredGlobals);
        self::assertArrayHasKey('Acme\\Forced', $policy->overrideNamespaces);
        self::assertArrayHasKey('Acme_Forced', $policy->overrideGlobals);
    }

    public function testPackageWildcardMatchesInstalledNames(): void
    {
        $policy = Policy::from(
            [
                'ignore' => [
                    [
                        'type' => 'package',
                        'value' => 'symfony/polyfill-*',
                        'reason' => 'Keep extension fallbacks global.',
                    ],
                ],
            ],
            'vendor',
            'dist',
        );

        self::assertSame(['symfony/polyfill-ctype', 'symfony/polyfill-mbstring'], $policy->packagesMatching(['acme/lib', 'symfony/polyfill-ctype', 'symfony/polyfill-mbstring'], 'symfony/polyfill-*'));
    }

    public function testRequiresAReason(): void
    {
        $this->expectException(ScoperException::class);

        Policy::from(
            [
                'ignore' => [['type' => 'package', 'value' => 'acme/library']],
            ],
            'vendor',
            'dist',
        );
    }

    public function testRejectsUnknownEntryKeys(): void
    {
        $this->expectException(ScoperException::class);

        Policy::from(
            [
                'ignore' => [['type' => 'package', 'value' => 'acme/library', 'reason' => 'Because.', 'typo' => true]],
            ],
            'vendor',
            'dist',
        );
    }

    public function testRejectsWildcardVendors(): void
    {
        $this->expectException(ScoperException::class);

        Policy::from(
            [
                'ignore' => [['type' => 'package', 'value' => '*/library', 'reason' => 'Because.']],
            ],
            'vendor',
            'dist',
        );
    }

    public function testRejectsExcludeOutsideRemovedPaths(): void
    {
        $this->expectException(ScoperException::class);

        Policy::from(
            [
                'ignore' => [['type' => 'path', 'value' => 'vendor/acme/library', 'exclude' => 'license*', 'reason' => 'Because.']],
            ],
            'vendor',
            'dist',
        );
    }

    public function testRejectsPathsOutsideVendorAndDist(): void
    {
        $this->expectException(ScoperException::class);

        Policy::from(
            [
                'remove' => [['type' => 'path', 'value' => 'src/File.php', 'reason' => 'Because.']],
            ],
            'vendor',
            'dist',
        );
    }

    public function testRejectsDuplicateDecisions(): void
    {
        $this->expectException(ScoperException::class);

        Policy::from(
            [
                'ignore' => [['type' => 'package', 'value' => 'acme/library', 'reason' => 'First.'], ['type' => 'package', 'value' => 'acme/library', 'reason' => 'Second.']],
            ],
            'vendor',
            'dist',
        );
    }
}
