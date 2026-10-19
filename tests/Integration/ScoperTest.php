<?php

declare(strict_types=1);

namespace CleanPlugins\Scoper\Tests\Integration;

use CleanPlugins\Scoper\Scope\Scoper;
use CleanPlugins\Scoper\Support\Filesystem;
use CleanPlugins\Scoper\Tests\TestCase;

final class ScoperTest extends TestCase
{
    public function testScopesVendorNamespacesAndOwnImportsInPlace(): void
    {
        $this->write(
            'build/vendor/acme/library/src/Thing.php',
            <<<'PHP'
            <?php

            namespace Acme\Library;

            class Thing
            {
            }
            PHP
            ,
        );

        $plugin = <<<'PHP'
        <?php

        namespace Fixture\Project;

        use Acme\Library\Thing;

        final class Plugin
        {
            public function thing(): Thing
            {
                return new Thing();
            }
        }
        PHP;

        $this->write('build/src/Plugin.php', $plugin);

        $config = $this->config([
            'prefix' => 'Vendor\\Build',
            'source' => ['src'],
        ]);

        $result = (new Scoper($config, new Filesystem($this->tmp)))->run();

        self::assertSame(1, $result->namespaces);
        self::assertSame(2, $result->filesRewritten);
        self::assertStringContainsString('namespace Vendor\\Build\\Acme\\Library;', $this->read('build/vendor/acme/library/src/Thing.php'));
        self::assertStringContainsString('use Vendor\\Build\\Acme\\Library\\Thing;', $this->read('build/src/Plugin.php'));
        self::assertStringNotContainsString('use Acme\\Library\\Thing;', $this->read('build/src/Plugin.php'));
        self::assertFileExists($config->mapPath());
        self::assertSame($plugin, str_replace('use Vendor\\Build\\Acme\\Library\\Thing;', 'use Acme\\Library\\Thing;', $this->read('build/src/Plugin.php')));
    }
}
