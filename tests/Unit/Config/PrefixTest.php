<?php

declare(strict_types=1);

namespace CleanPlugins\Scoper\Tests\Unit\Config;

use CleanPlugins\Scoper\Config\Prefix;
use CleanPlugins\Scoper\Exception\ScoperException;
use CleanPlugins\Scoper\Tests\TestCase;

final class PrefixTest extends TestCase
{
    public function testDerivesEveryGlobalPrefixStyle(): void
    {
        $prefix = Prefix::from([
            'namespace' => 'Vendor\\Package',
            'global' => ['base' => 'Vendor Package'],
        ]);

        self::assertSame(
            [
                'screaming_snake' => 'VENDOR_PACKAGE_',
                'snake_pascal' => 'Vendor_Package_',
                'snake_case' => 'vendor_package_',
                'pascal' => 'VendorPackage',
                'camel' => 'vendorPackage',
            ],
            $prefix->globals,
        );
        self::assertSame('snake_pascal', $prefix->style('Less_Parser'));
        self::assertSame('Vendor_Package_Less_Parser', $prefix->render('Less_Parser'));
    }

    public function testExplicitStyleWinsOverTheDerivedValue(): void
    {
        $prefix = Prefix::from([
            'namespace' => 'Acme\\Build',
            'global' => [
                'base' => 'Acme Build',
                'snake_case' => 'custom_prefix_',
            ],
        ]);

        self::assertSame('custom_prefix_', $prefix->globals['snake_case']);
        self::assertSame('ACME_BUILD_', $prefix->globals['screaming_snake']);
    }

    public function testStringPrefixIsAccepted(): void
    {
        $prefix = Prefix::from('Vendor\\Package');

        self::assertSame('Vendor\\Package', $prefix->namespace);
        self::assertSame('Vendor\\Package\\Thing', $prefix->namespace . '\\Thing');
        self::assertSame('VENDOR_PACKAGE_', $prefix->globals['screaming_snake']);
    }

    public function testRejectsAnEmptyNamespace(): void
    {
        $this->expectException(ScoperException::class);

        Prefix::from('\\');
    }

    public function testRejectsUnknownGlobalStyles(): void
    {
        $this->expectException(ScoperException::class);

        Prefix::from([
            'namespace' => 'Acme\\Build',
            'global' => ['unknown' => 'value'],
        ]);
    }
}
