<?php

declare(strict_types=1);

namespace CleanPlugins\Scoper\Support;

use CleanPlugins\Scoper\Exception\ScoperException;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * File operations rooted at the project directory. Every policy path is project-relative.
 */
final class Filesystem
{
    private readonly string $root;

    public function __construct(string $root)
    {
        $this->root = Path::normalize($root);
    }

    public function root(): string
    {
        return $this->root;
    }

    public function absolute(string $path): string
    {
        return Path::isAbsolute($path) ? Path::normalize($path) : Path::join($this->root, $path);
    }

    public function path(string $relative = ''): string
    {
        return $relative === '' ? $this->root : $this->absolute($relative);
    }

    public function exists(string $path): bool
    {
        return file_exists($this->absolute($path));
    }

    public function isFile(string $path): bool
    {
        return is_file($this->absolute($path));
    }

    public function isDirectory(string $path): bool
    {
        return is_dir($this->absolute($path));
    }

    public function read(string $path): string
    {
        $absolute = $this->absolute($path);
        $content = @file_get_contents($absolute);

        if ($content === false) {
            throw new ScoperException("Could not read {$absolute}");
        }

        return $content;
    }

    public function write(string $path, string $content): void
    {
        $absolute = $this->absolute($path);
        $directory = dirname($absolute);

        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new ScoperException("Could not create {$directory}");
        }

        if (@file_put_contents($absolute, $content) === false) {
            throw new ScoperException("Could not write {$absolute}");
        }
    }

    public function mkdir(string $path): void
    {
        $absolute = $this->absolute($path);

        if (!is_dir($absolute) && !@mkdir($absolute, 0777, true) && !is_dir($absolute)) {
            throw new ScoperException("Could not create {$absolute}");
        }
    }

    /**
     * Every file under a directory (or the file itself), as absolute forward-slashed paths.
     *
     * The order is the same as the old build's recursive, name-sorted walk: it is part of the tree
     * hash, so changing it would make two identical trees hash differently.
     *
     * @return list<string>
     */
    public function files(string $path): array
    {
        $absolute = $this->absolute($path);

        if (is_file($absolute)) {
            return [Path::normalize($absolute)];
        }

        if (!is_dir($absolute)) {
            return [];
        }

        $files = [];
        $this->walk($absolute, $files);

        return $files;
    }

    /** @param list<string> $files */
    private function walk(string $directory, array &$files): void
    {
        $entries = scandir($directory);

        if ($entries === false) {
            return;
        }

        sort($entries, SORT_STRING);

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $directory . '/' . $entry;

            if (is_dir($full) && !is_link($full)) {
                $this->walk($full, $files);

                continue;
            }

            if (is_file($full) || is_link($full)) {
                $files[] = Path::normalize($full);
            }
        }
    }

    /**
     * Every file under a path, as paths relative to a base (the project root by default).
     *
     * @return list<string>
     */
    public function relativeFiles(string $path, ?string $base = null): array
    {
        $base ??= $this->root;

        return array_map(static fn(string $file): string => Path::relative($base, $file), $this->files($path));
    }

    public function copy(string $source, string $target, ?callable $tick = null): void
    {
        $source = $this->absolute($source);
        $target = $this->absolute($target);

        if (is_file($source)) {
            $directory = dirname($target);

            if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
                throw new ScoperException("Could not create {$directory}");
            }

            if (!@copy($source, $target)) {
                throw new ScoperException("Could not copy {$source} to {$target}");
            }

            if ($tick !== null) {
                $tick();
            }

            return;
        }

        if (!is_dir($source)) {
            throw new ScoperException("Source path does not exist: {$source}");
        }

        if (!is_dir($target) && !@mkdir($target, 0777, true) && !is_dir($target)) {
            throw new ScoperException("Could not create {$target}");
        }

        foreach (scandir($source) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $this->copy($source . '/' . $entry, $target . '/' . $entry, $tick);
        }
    }

    public function remove(string $path): void
    {
        $absolute = $this->absolute($path);

        if (!file_exists($absolute) && !is_link($absolute)) {
            return;
        }

        if (is_file($absolute) || is_link($absolute)) {
            if (!@unlink($absolute) && file_exists($absolute)) {
                throw new ScoperException("Could not remove {$absolute}");
            }

            return;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isDir() && !$file->isLink()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }

        if (!@rmdir($absolute) && is_dir($absolute)) {
            throw new ScoperException("Could not remove {$absolute}");
        }
    }

    public function size(string $path): int
    {
        $bytes = 0;

        foreach ($this->files($path) as $file) {
            $bytes += (int) filesize($file);
        }

        return $bytes;
    }

    public function hash(string $path): string
    {
        $absolute = $this->absolute($path);
        $combined = hash_init('sha256');

        foreach ($this->files($path) as $file) {
            $relative = Path::relative($absolute, $file);
            hash_update($combined, $relative . ' ' . hash_file('sha256', $file) . "\n");
        }

        return hash_final($combined);
    }
}
