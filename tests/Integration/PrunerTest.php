<?php

declare(strict_types=1);

namespace CleanPlugins\Scoper\Tests\Integration;

use CleanPlugins\Scoper\Prune\Pruner;
use CleanPlugins\Scoper\Support\Filesystem;
use CleanPlugins\Scoper\Tests\TestCase;

final class PrunerTest extends TestCase
{
    public function testUnmatchedPathsAreReportedSeparately(): void
    {
        $this->write('build/vendor/acme/library/README.md', "# Library\n");

        $config = $this->config([
            'prefix' => 'Vendor\\Build',
            'source' => ['src'],
            'remove' => [
                ['type' => 'path', 'value' => 'vendor/**/*.txt', 'reason' => 'test'],
            ],
        ]);

        $result = (new Pruner($config, new Filesystem($this->tmp)))->run();

        self::assertSame([], $result->warnings);
        self::assertCount(1, $result->unused);
        self::assertStringContainsString('vendor/**/*.txt', $result->unused[0]);
    }

    public function testMatchedPathsAreNotReported(): void
    {
        $this->write('build/vendor/acme/library/README.md', "# Library\n");

        $config = $this->config([
            'prefix' => 'Vendor\\Build',
            'source' => ['src'],
            'remove' => [
                ['type' => 'path', 'value' => 'vendor/**/*.md', 'reason' => 'test'],
            ],
        ]);

        $result = (new Pruner($config, new Filesystem($this->tmp)))->run();

        self::assertSame([], $result->warnings);
        self::assertSame([], $result->unused);
        self::assertSame(1, $result->files);
        self::assertSame(['vendor/acme/library/README.md'], $result->deleted);
    }

    public function testRecordsRewrittenFiles(): void
    {
        $this->write('build/vendor/composer/installed.json', json_encode([
            'packages' => [
                ['name' => 'acme/library'],
            ],
        ], JSON_PRETTY_PRINT) . PHP_EOL);
        $this->write('build/vendor/acme/library/src/Thing.php', "<?php\n\nreturn 'Legacy_';\n");

        $config = $this->config([
            'prefix' => 'Vendor\\Build',
            'source' => ['src'],
            'remove' => [
                ['type' => 'string', 'value' => 'Legacy_', 'package' => 'acme/library', 'reason' => 'test'],
            ],
        ]);

        $result = (new Pruner($config, new Filesystem($this->tmp)))->run();

        $relative = 'vendor/acme/library/src/Thing.php';
        self::assertSame([], $result->deleted);
        self::assertArrayHasKey($relative, $result->rewritten);
        self::assertSame(hash('sha256', (string) file_get_contents($this->tmp . '/build/' . $relative)), $result->rewritten[$relative]);
    }
}
