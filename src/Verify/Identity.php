<?php

declare(strict_types=1);

namespace CleanPlugins\Scoper\Verify;

use CleanPlugins\Scoper\Config\Config;
use CleanPlugins\Scoper\Config\Glob;
use CleanPlugins\Scoper\Exception\ScoperException;
use CleanPlugins\Scoper\Support\Filesystem;
use CleanPlugins\Scoper\Support\Path;

/**
 * The project's own code may differ from the source only in the edits the scoper recorded — no
 * reformatting, no rewritten application symbols, no injected namespaces. An ignored package keeps
 * its own declarations; only its references to *scoped* packages may be rewritten.
 */
final class Identity
{
    public function __construct(private readonly Config $config, private readonly Filesystem $files) {}

    /** @return list<string> build-relative own files */
    public function ownFiles(): array
    {
        $buildDir = $this->config->outputPath();
        $files = [];

        foreach ($this->ownRoots() as $root) {
            if (is_dir($buildDir . '/' . $root)) {
                foreach ($this->files->relativeFiles($buildDir . '/' . $root, $buildDir) as $file) {
                    $files[] = $file;
                }

                continue;
            }

            if (is_file($buildDir . '/' . $root)) {
                $files[] = $root;

                continue;
            }

            if ($this->config->own !== []) {
                throw new ScoperException("verify.own path not found in the build: {$root}");
            }
        }

        return array_values(array_unique($files));
    }

    /** @param array<string, mixed> $map */
    public function assertIntact(array $map): int
    {
        $buildDir = $this->config->outputPath();
        $edits = $map['edits'] ?? [];
        $files = $this->ownFiles();
        $changed = [];

        foreach ($files as $file) {
            $sourcePath = $this->config->absolute($file);
            $buildPath = $buildDir . '/' . $file;

            if (!is_file($sourcePath) || !is_file($buildPath)) {
                throw new ScoperException("Own-code gate cannot read {$file} from the source and the build");
            }

            $expected = $this->apply($this->files->read($sourcePath), $edits[$file] ?? []);

            if ($expected !== $this->files->read($buildPath)) {
                $changed[] = $file;
            }
        }

        if ($changed !== []) {
            throw new ScoperException('Own code was modified beyond the recorded edits', $changed);
        }

        return count($files);
    }

    /** @param array<string, mixed> $map */
    public function assertIgnoredPackages(array $map): int
    {
        $scoped = (string) ($map['prefix'] ?? '') . '\\';
        $excluded = $map['excluded']['paths'] ?? [];
        $checked = 0;

        foreach ($map['edits'] ?? [] as $file => $edits) {
            if (!str_starts_with((string) $file, $this->config->vendor . '/') || !Glob::isIgnored((string) $file, $excluded)) {
                continue;
            }

            foreach ($edits as $edit) {
                ++$checked;

                if (str_starts_with((string) $edit['to'], $scoped) && !str_starts_with((string) $edit['from'], $scoped)) {
                    continue;
                }

                throw new ScoperException("{$file} is inside an ignored package but was rewritten in a way that is not a scoped reference", ["{$edit['from']} → {$edit['to']}"]);
            }
        }

        return $checked;
    }

    /** @return list<string> */
    private function ownRoots(): array
    {
        if ($this->config->own !== []) {
            return array_values(array_unique($this->config->own));
        }

        $autoload = $this->config->composerJson['autoload'] ?? [];
        $roots = [];

        foreach (['psr-4', 'psr-0'] as $type) {
            foreach ((array) ($autoload[$type] ?? []) as $entry) {
                foreach ((array) $entry as $path) {
                    if (is_string($path) && trim($path) !== '') {
                        $roots[] = trim(str_replace('\\', '/', $path), '/');
                    }
                }
            }
        }

        foreach ((array) ($autoload['classmap'] ?? []) as $path) {
            if (is_string($path) && trim($path) !== '') {
                $roots[] = trim(str_replace('\\', '/', $path), '/');
            }
        }

        foreach ((array) ($autoload['files'] ?? []) as $path) {
            if (is_string($path) && trim($path) !== '') {
                $roots[] = trim(str_replace('\\', '/', $path), '/');
            }
        }

        return array_values(array_unique($roots));
    }

    /** @param list<array{start: int, end: int, from: string, to: string}> $edits */
    private function apply(string $source, array $edits): string
    {
        usort($edits, static fn(array $a, array $b): int => $b['start'] <=> $a['start']);

        foreach ($edits as $edit) {
            $source = substr_replace($source, $edit['to'], $edit['start'], $edit['end'] - $edit['start'] + 1);
        }

        return $source;
    }
}
