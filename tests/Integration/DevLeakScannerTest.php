<?php

declare(strict_types=1);

namespace CleanPlugins\Scoper\Tests\Integration;

use CleanPlugins\Scoper\Support\Filesystem;
use CleanPlugins\Scoper\Tests\TestCase;
use CleanPlugins\Scoper\Verify\DevLeakScanner;

final class DevLeakScannerTest extends TestCase
{
    public function testReportsAReferenceToADevOnlyPackage(): void
    {
        $config = $this->config();
        $this->installed([
            'acme/dev' => ['psr-4' => ['Acme\\Dev\\' => 'src/']],
            'acme/prod' => ['psr-4' => ['Acme\\Prod\\' => 'src/']],
        ], ['acme/dev']);

        $this->write('build/src/Plugin.php', <<<'PHP'
        <?php

        use Acme\Dev\Thing;

        final class Plugin
        {
        }
        PHP);

        $ticks = 0;
        $result = (new DevLeakScanner($config, new Filesystem($this->tmp)))->run(function () use (&$ticks): void {
            ++$ticks;
        });

        self::assertCount(1, $result->leaks);
        self::assertGreaterThan(0, $ticks);
        self::assertStringContainsString('Acme\\Dev\\Thing', $result->leaks[0]);
        self::assertStringContainsString('acme/dev', $result->leaks[0]);
        self::assertStringContainsString('src/Plugin.php:3', $result->leaks[0]);
    }

    public function testIgnoresProductionSymbolsAndSymbolsDeclaredInTheBuild(): void
    {
        $config = $this->config();
        $this->installed([
            'acme/dev' => ['psr-4' => ['Acme\\Dev\\' => 'src/']],
            'acme/prod' => ['psr-4' => ['Acme\\Prod\\' => 'src/']],
        ], ['acme/dev']);

        $this->write('build/src/Plugin.php', <<<'PHP'
        <?php

        use Acme\Prod\Thing;

        final class Plugin
        {
        }
        PHP);

        $this->write('build/src/Thing.php', <<<'PHP'
        <?php

        namespace Acme\Dev;

        class Thing
        {
        }
        PHP);

        $this->write('build/src/Another.php', <<<'PHP'
        <?php

        use Acme\Dev\Thing;

        final class Another
        {
        }
        PHP);

        $result = (new DevLeakScanner($config, new Filesystem($this->tmp)))->run();

        self::assertSame([], $result->leaks);
    }

    public function testReportsADevOnlyGlobalClassFromTheClassmap(): void
    {
        $config = $this->config();
        $this->installed([
            'acme/legacy' => ['classmap' => ['src/']],
            'acme/prod' => ['psr-4' => ['Acme\\Prod\\' => 'src/']],
        ], ['acme/legacy']);

        $this->write('vendor/acme/legacy/src/Legacy_Thing.php', <<<'PHP'
        <?php

        class Legacy_Thing
        {
        }
        PHP);

        $this->write('build/plugin.php', <<<'PHP'
        <?php

        new Legacy_Thing();
        PHP);

        $result = (new DevLeakScanner($config, new Filesystem($this->tmp)))->run();

        self::assertCount(1, $result->leaks);
        self::assertStringContainsString('Legacy_Thing', $result->leaks[0]);
        self::assertStringContainsString('acme/legacy', $result->leaks[0]);
    }

    public function testReportsADevOnlyGlobalFunctionFromTheFilesAutoload(): void
    {
        $config = $this->config();
        $this->installed([
            'acme/functions' => ['files' => ['src/functions.php']],
            'acme/prod' => ['psr-4' => ['Acme\\Prod\\' => 'src/']],
        ], ['acme/functions']);

        $this->write('vendor/acme/functions/src/functions.php', <<<'PHP'
        <?php

        function acme_dev_helper(): void
        {
        }
        PHP);

        $this->write('build/plugin.php', <<<'PHP'
        <?php

        acme_dev_helper();
        PHP);

        $result = (new DevLeakScanner($config, new Filesystem($this->tmp)))->run();

        self::assertCount(1, $result->leaks);
        self::assertStringContainsString('acme_dev_helper', $result->leaks[0]);
        self::assertStringContainsString('acme/functions', $result->leaks[0]);
    }

    public function testIgnoresReferencesInsideProductionVendorCode(): void
    {
        $config = $this->config();
        $this->installed([
            'acme/dev' => ['psr-4' => ['Acme\\Dev\\' => 'src/']],
            'acme/prod' => ['psr-4' => ['Acme\\Prod\\' => 'src/']],
        ], ['acme/dev']);

        $this->write('build/vendor/acme/prod/src/Test.php', <<<'PHP'
        <?php

        use Acme\Dev\Thing;

        final class Test
        {
        }
        PHP);

        $result = (new DevLeakScanner($config, new Filesystem($this->tmp)))->run();

        self::assertSame([], $result->leaks);
    }

    public function testReportsADevOnlyClassNameInsideAString(): void
    {
        $config = $this->config();
        $this->installed([
            'acme/dev' => ['psr-4' => ['Acme\\Dev\\' => 'src/']],
            'acme/prod' => ['psr-4' => ['Acme\\Prod\\' => 'src/']],
        ], ['acme/dev']);

        $this->write('vendor/acme/dev/src/Thing.php', <<<'PHP'
        <?php

        namespace Acme\Dev;

        class Thing
        {
        }
        PHP);

        $this->write('build/plugin.php', <<<'PHP'
        <?php

        class_exists('Acme\Dev\Thing');
        PHP);

        $result = (new DevLeakScanner($config, new Filesystem($this->tmp)))->run();

        self::assertCount(1, $result->leaks);
        self::assertStringContainsString('Acme\\Dev\\Thing', $result->leaks[0]);
    }

    public function testIgnoresADevNamespaceStringThatNamesNoSymbol(): void
    {
        $config = $this->config();
        $this->installed([
            'acme/dev' => ['psr-4' => ['Acme\\Dev\\' => 'src/']],
        ], ['acme/dev']);

        $this->write('build/plugin.php', <<<'PHP'
        <?php

        $hook = 'Acme\Dev\before_save';
        PHP);

        $result = (new DevLeakScanner($config, new Filesystem($this->tmp)))->run();

        self::assertSame([], $result->leaks);
    }

    /** @param array<string, array<string, mixed>> $packages @param list<string> $devNames */
    private function installed(array $packages, array $devNames): void
    {
        $entries = [];
        $production = [];

        foreach ($packages as $name => $autoload) {
            $entry = ['name' => $name, 'autoload' => $autoload];
            $entries[] = $entry;

            if (!in_array($name, $devNames, true)) {
                $production[] = ['name' => $name];
            }
        }

        $this->write('vendor/composer/installed.json', json_encode([
            'packages' => $entries,
            'dev-package-names' => $devNames,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

        $this->write('.build/installed.json', json_encode([
            'packages' => $production,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }
}
