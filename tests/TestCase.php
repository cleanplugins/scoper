<?php

declare(strict_types=1);

namespace CleanPlugins\Scoper\Tests;

use CleanPlugins\Scoper\Config\Config;
use CleanPlugins\Scoper\Support\Path;
use PHPUnit\Framework\TestCase as BaseTestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

abstract class TestCase extends BaseTestCase
{
    protected string $tmp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmp = Path::join(sys_get_temp_dir(), 'scoper-test-' . bin2hex(random_bytes(8)));

        if (!is_dir($this->tmp) && !mkdir($this->tmp, 0777, true) && !is_dir($this->tmp)) {
            self::fail("Could not create {$this->tmp}");
        }

        $this->tmp = Path::normalize(realpath($this->tmp) ?: $this->tmp);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmp);

        parent::tearDown();
    }

    /** @param array<string, mixed> $settings @param array<string, mixed> $composer */
    protected function manifest(array $settings = [], array $composer = []): string
    {
        $json = [
            'name' => 'acme/project',
            'autoload' => [
                'psr-4' => [
                    'Acme\\Project\\' => 'src/',
                ],
            ],
        ];

        $json = array_replace_recursive($json, $composer);
        $json['extra']['scoper'] = array_replace_recursive(
            [
                'prefix' => 'Vendor\\Package',
                'source' => ['src'],
            ],
            $settings,
        );

        return $this->write('composer.json', json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }

    /** @param array<string, mixed> $settings @param array<string, mixed> $composer */
    protected function config(array $settings = [], array $composer = []): Config
    {
        $this->manifest($settings, $composer);

        return Config::load($this->tmp);
    }

    protected function write(string $relative, string $contents): string
    {
        $path = Path::join($this->tmp, $relative);
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            self::fail("Could not create {$directory}");
        }

        if (file_put_contents($path, $contents) === false) {
            self::fail("Could not write {$path}");
        }

        return $path;
    }

    protected function read(string $relative): string
    {
        return (string) file_get_contents(Path::join($this->tmp, $relative));
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

        /** @var SplFileInfo $entry */
        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }

        @rmdir($path);
    }
}
