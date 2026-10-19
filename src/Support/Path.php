<?php

declare(strict_types=1);

namespace CleanPlugins\Scoper\Support;

/**
 * Forward-slashed path arithmetic that works with Windows drive letters.
 */
final class Path
{
    /** Collapse `.` and `..` without touching the filesystem. */
    public static function normalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $prefix = '';

        if (preg_match('#^[A-Za-z]:/#', $path, $matches) === 1) {
            $prefix = strtoupper($matches[0]);
            $path = substr($path, strlen($matches[0]));
        } elseif (str_starts_with($path, '/')) {
            $prefix = '/';
            $path = ltrim($path, '/');
        }

        $parts = [];

        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                if ($parts !== [] && end($parts) !== '..') {
                    array_pop($parts);
                } elseif ($prefix === '') {
                    $parts[] = '..';
                }

                continue;
            }

            $parts[] = $part;
        }

        $joined = implode('/', $parts);

        return $prefix === '/' ? '/' . $joined : $prefix . $joined;
    }

    public static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1;
    }

    public static function join(string ...$parts): string
    {
        return self::normalize(implode('/', array_filter($parts, static fn(string $part): bool => $part !== '')));
    }

    /** A `../`-style path from a directory to a path, both absolute. */
    public static function relative(string $base, string $path): string
    {
        $base = self::normalize($base);
        $path = self::normalize($path);

        if ($base === $path) {
            return '';
        }

        $baseParts = $base === '' ? [] : explode('/', $base);
        $pathParts = $path === '' ? [] : explode('/', $path);

        while ($baseParts !== [] && $pathParts !== [] && strcasecmp($baseParts[0], $pathParts[0]) === 0) {
            array_shift($baseParts);
            array_shift($pathParts);
        }

        return rtrim(str_repeat('../', count($baseParts)) . implode('/', $pathParts), '/');
    }

    public static function isInside(string $path, string $parent): bool
    {
        $path = self::normalize($path);
        $parent = self::normalize($parent);

        return $path === $parent || str_starts_with($path, $parent . '/');
    }
}
