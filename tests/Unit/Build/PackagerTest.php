<?php

declare(strict_types=1);

namespace CleanPlugins\Scoper\Tests\Unit\Build;

use CleanPlugins\Scoper\Build\Packager;
use CleanPlugins\Scoper\Exception\ScoperException;
use CleanPlugins\Scoper\Support\Filesystem;
use CleanPlugins\Scoper\Support\Path;
use CleanPlugins\Scoper\Tests\TestCase;
use ZipArchive;

final class PackagerTest extends TestCase
{
    public function testCreatesAReleaseFromTheOutputTree(): void
    {
        $config = $this->config(['releases' => 'releases']);
        $this->write('build/plugin.php', '<?php');
        $this->write('build/assets/style.css', 'body {}');

        $result = (new Packager($config, new Filesystem($this->tmp)))->package('1.2.3');

        self::assertSame(Path::join($this->tmp, 'releases/project-1.2.3.zip'), $result['path']);
        self::assertSame(2, $result['entries']);
        self::assertGreaterThan(0, $result['size']);

        $zip = new ZipArchive();
        self::assertTrue($zip->open($result['path']));
        self::assertSame(['assets/style.css', 'plugin.php'], $this->entries($zip));
        $zip->close();
    }

    public function testRejectsAnArchiveNestedUnderTheOutputDirectory(): void
    {
        $config = $this->config(['output' => 'build']);
        $this->write('build/build/plugin.php', '<?php');

        $this->expectException(ScoperException::class);

        (new Packager($config, new Filesystem($this->tmp)))->package(null);
    }

    /** @return list<string> */
    private function entries(ZipArchive $zip): array
    {
        $entries = [];

        for ($index = 0; $index < $zip->numFiles; ++$index) {
            $entries[] = (string) $zip->getNameIndex($index);
        }

        sort($entries);

        return $entries;
    }
}
