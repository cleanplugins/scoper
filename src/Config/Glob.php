<?php

declare(strict_types=1);

namespace CleanPlugins\Scoper\Config;

/**
 * Glob semantics shared by `ignore` and `remove`:
 *
 *   `*`  one path segment
 *   `**` any characters
 *   `**` followed by `/` zero or more whole segments
 *
 * Matching is case-insensitive. A pattern naming a directory covers everything under it, and a glob
 * that matches a directory covers its contents too.
 */
final class Glob
{
    /** @param list<string> $patterns */
    public static function isIgnored(string $relative, array $patterns): bool
    {
        $relative = trim($relative, '/');

        foreach ($patterns as $pattern) {
            $pattern = trim((string) $pattern, '/');

            if ($pattern === '') {
                continue;
            }

            if (!str_contains($pattern, '*')) {
                $prefix = $pattern . '/';

                if (strcasecmp($relative, $pattern) === 0 || strncasecmp($relative, $prefix, strlen($prefix)) === 0) {
                    return true;
                }

                continue;
            }

            $regex = self::regex($pattern);
            $candidate = $relative;

            while (true) {
                if (preg_match($regex, $candidate) === 1) {
                    return true;
                }

                $parent = dirname($candidate);

                if ($parent === '.' || $parent === '' || $parent === $candidate) {
                    break;
                }

                $candidate = str_replace('\\', '/', $parent);
            }
        }

        return false;
    }

    /** @param list<string> $patterns */
    public static function isExcluded(array $patterns, string $path): bool
    {
        $name = basename($path);

        foreach ($patterns as $pattern) {
            if (self::matchesName($name, (string) $pattern)) {
                return true;
            }
        }

        return false;
    }

    public static function matchesName(string $name, string $pattern): bool
    {
        return preg_match(self::regex($pattern), $name) === 1;
    }

    /** @param list<string> $installed @return list<string> */
    public static function packagesMatching(array $installed, string $pattern): array
    {
        return array_values(array_filter($installed, static fn(string $name): bool => self::isIgnored($name, [$pattern])));
    }

    public static function regex(string $pattern): string
    {
        $regex = '';
        $length = strlen($pattern);

        for ($i = 0; $i < $length; ++$i) {
            $char = $pattern[$i];

            if ($char === '*') {
                if (($pattern[$i + 1] ?? '') === '*') {
                    if (($pattern[$i + 2] ?? '') === '/') {
                        $regex .= '(?:[^/]+/)*';
                        $i += 2;

                        continue;
                    }

                    $regex .= '.*';
                    ++$i;

                    continue;
                }

                $regex .= '[^/]*';

                continue;
            }

            $regex .= preg_quote($char, '#');
        }

        return '#^' . $regex . '$#i';
    }
}
