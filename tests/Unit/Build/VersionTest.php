<?php

declare(strict_types=1);

namespace CleanPlugins\Scoper\Tests\Unit\Build;

use CleanPlugins\Scoper\Build\Version;
use CleanPlugins\Scoper\Exception\ScoperException;
use CleanPlugins\Scoper\Tests\TestCase;

final class VersionTest extends TestCase
{
    public function testReadsTheWordPressVersionHeader(): void
    {
        $this->write(
            'plugin.php',
            <<<'PHP'
            <?php
            /**
             * Version: 1.2.3
             */
            PHP
            ,
        );

        $config = $this->config([
            'release' => [
                'plugin_file' => 'plugin.php',
            ],
        ]);

        self::assertSame('1.2.3', (new Version($config))->read());
    }

    public function testReadsAHeaderOnACommentLine(): void
    {
        $this->write('plugin.php', '<?php /* Version: 1.2.3 */');

        $config = $this->config([
            'release' => [
                'plugin_file' => 'plugin.php',
            ],
        ]);

        self::assertSame('1.2.3', (new Version($config))->read());
    }

    public function testNormalizesCrOnlyLineEndings(): void
    {
        $this->write('plugin.php', "<?php\r/**\r * Version: 1.2.3\r */");

        $config = $this->config([
            'release' => [
                'plugin_file' => 'plugin.php',
            ],
        ]);

        self::assertSame('1.2.3', (new Version($config))->read());
    }

    public function testIgnoresAHeaderAfterTheFirstEightKilobytes(): void
    {
        $this->write('plugin.php', str_repeat('x', 8192) . "\n * Version: 9.9.9\n");

        $config = $this->config([
            'release' => [
                'plugin_file' => 'plugin.php',
            ],
        ]);

        $this->expectException(ScoperException::class);

        (new Version($config))->read();
    }

    public function testRejectsAMissingHeader(): void
    {
        $this->write('plugin.php', '<?php');

        $config = $this->config([
            'release' => [
                'plugin_file' => 'plugin.php',
            ],
        ]);

        $this->expectException(ScoperException::class);

        (new Version($config))->read();
    }

    public function testRejectsAMissingPluginFile(): void
    {
        $config = $this->config([
            'release' => [
                'plugin_file' => 'missing.php',
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
