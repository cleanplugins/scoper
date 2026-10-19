<?php

declare(strict_types=1);

namespace CleanPlugins\Scoper\Tests\Unit\Scope;

use CleanPlugins\Scoper\Config\Prefix;
use CleanPlugins\Scoper\Scope\SymbolTable;
use CleanPlugins\Scoper\Tests\TestCase;

final class SymbolTableTest extends TestCase
{
    private function symbols(): SymbolTable
    {
        return new SymbolTable(Prefix::from('Vendor\\Package'), ['Acme\\Library' => ['file' => 'vendor/acme/library/src/Library.php']], ['Acme\\Protected', 'Acme\\Library\\Kept'], ['Acme_Global' => ['to' => 'Vendor_Package_Acme_Global']], ['Acme_Protected_Global' => 'protected by policy']);
    }

    public function testResolvesDeclaredNamespacesAndDescendants(): void
    {
        $symbols = $this->symbols();

        self::assertSame('Vendor\\Package\\Acme\\Library', $symbols->resolve('Acme\\Library'));
        self::assertSame('Vendor\\Package\\Acme\\Library\\Deep', $symbols->resolve('Acme\\Library\\Deep'));
        self::assertNull($symbols->resolve('Acme\\LibraryOther'));
    }

    public function testProtectionWinsOverDeclarations(): void
    {
        $symbols = $this->symbols();

        self::assertNull($symbols->resolve('Acme\\Protected'));
        self::assertNull($symbols->resolve('Acme\\Protected\\Thing'));
        self::assertNull($symbols->resolve('Acme\\Library\\Kept'));
    }

    public function testResolvesGlobalsAndKeepsProtectedGlobals(): void
    {
        $symbols = $this->symbols();

        self::assertSame('Vendor_Package_Acme_Global', $symbols->resolve('Acme_Global'));
        self::assertNull($symbols->resolve('Acme_Protected_Global'));
    }

    public function testDetectsSymbolPrefixes(): void
    {
        $symbols = $this->symbols();

        self::assertTrue($symbols->symbolIshPrefix('Acme\\Lib'));
        self::assertTrue($symbols->symbolIshPrefix('Acme_'));
        self::assertFalse($symbols->symbolIshPrefix('Hi'));
    }
}
