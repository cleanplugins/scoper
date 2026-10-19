<?php

declare(strict_types=1);

namespace CleanPlugins\Scoper\Build;

use CleanPlugins\Scoper\Config\Config;
use CleanPlugins\Scoper\Exception\ScoperException;
use CleanPlugins\Scoper\Support\Filesystem;
use CleanPlugins\Scoper\Support\Progress;
use ZipArchive;

final class Packager
{
    public function __construct(private readonly Config $config, private readonly Filesystem $files) {}

    /** @return array{path: string, entries: int, size: int} */
    public function package(?string $version, ?Progress $progress = null): array
    {
        $name = $this->config->releaseName . ($version !== null && $version !== '' ? '-' . $version : '');
        $path = $this->config->absolute($this->config->releases . '/' . $name . '.zip');

        $this->files->mkdir($this->config->releases);

        $zip = new ZipArchive();

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new ScoperException("Could not create the release archive: {$path}");
        }

        $buildDir = $this->config->outputPath();
        $files = $this->files->relativeFiles($buildDir, $buildDir);
        $progress?->total(count($files));

        foreach ($files as $relative) {
            $progress?->advance();
            $zip->addFromString($relative, $this->files->read($buildDir . '/' . $relative));
        }

        if (!$zip->close()) {
            throw new ScoperException("Could not write the release archive: {$path}");
        }

        $entries = [];

        if ($zip->open($path) === true) {
            for ($index = 0; $index < $zip->numFiles; ++$index) {
                $entries[] = (string) $zip->getNameIndex($index);
            }

            $zip->close();
        }

        $output = trim($this->config->output, '/');

        if ($output !== '' && array_filter($entries, static fn(string $entry): bool => str_starts_with($entry, $output . '/')) !== []) {
            throw new ScoperException("The archive nests everything under {$output}/ instead of the package root");
        }

        return ['path' => $path, 'entries' => count($entries), 'size' => (int) filesize($path)];
    }
}
