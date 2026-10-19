<?php

declare(strict_types=1);

namespace CleanPlugins\Scoper\Tests\Unit\Build;

use CleanPlugins\Scoper\Build\Version;
use CleanPlugins\Scoper\Exception\ScoperException;
use CleanPlugins\Scoper\Tests\TestCase;

final class VersionTest extends TestCase
{
    public function testReadsMatchingConstantAndHeader(): void
    {
        $this->write(
            'plugin.php',
            <<<'PHP'
            <?php
            /**
             * Version: 1.2.3
             */
            define('ACME_VERSION', '1.2.3');
            PHP
            ,
        );

        $config = $this->config([
            'release' => [
                'name' => 'acme',
                'version' => [
                    'file' => 'plugin.php',
                    'constant' => 'ACME_VERSION',
                    'header' => 'Version',
                ],
            ],
        ]);

        self::assertSame('1.2.3', (new Version($config))->read());
    }

    public function testRejectsDisagreeingConstantAndHeader(): void
    {
        $this->write(
            'plugin.php',
            <<<'PHP'
            <?php
            /**
             * Version: 1.2.4
             */
            define('ACME_VERSION', '1.2.3');
            PHP
            ,
        );

        $config = $this->config([
            'release' => [
                'version' => [
                    'file' => 'plugin.php',
                    'constant' => 'ACME_VERSION',
                    'header' => 'Version',
                ],
            ],
        ]);

        $this->expectException(ScoperException::class);

        (new Version($config))->read();
    }

    public function testRejectsAMissingConstant(): void
    {
        $this->write('plugin.php', '<?php');

        $config = $this->config([
            'release' => [
                'version' => [
                    'file' => 'plugin.php',
                    'constant' => 'ACME_VERSION',
                ],
            ],
        ]);

        $this->expectException(ScoperException::class);

        (new Version($config))->read();
    }

    public function testFallsBackToComposerVersion(): void
    {
        $config = $this->config([], ['version' => '2.0.1']);

        self::assertSame('2.0.1', (new Version($config))->read());
    }

    public function testReturnsNullWithoutAConfiguredVersion(): void
    {
        $config = $this->config();

        self::assertNull((new Version($config))->read());
    }
}
