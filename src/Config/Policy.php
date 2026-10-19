<?php

declare(strict_types=1);

namespace CleanPlugins\Scoper\Config;

use CleanPlugins\Scoper\Exception\ScoperException;
use CleanPlugins\Scoper\Support\Path;

/**
 * The `ignore`, `remove` and `override` policy from the project's composer.json.
 *
 * Every section is a list of entries with the same shape — `type`, `value`, `reason`, optional
 * `package` — so an entry says what it means without the key carrying it. Validation is strict: an
 * unknown key or type is an error, because a typo in a policy file silently does nothing. Giving
 * the same decision twice is an error too.
 */
final class Policy
{
    public const TYPES = [
        'ignore' => ['package', 'string', 'namespace', 'path', 'global'],
        'remove' => ['package', 'string', 'path'],
        'override' => ['namespace', 'global'],
    ];

    public const ENTRY_KEYS = ['type', 'value', 'reason', 'package', 'exclude'];

    /**
     * @param array<string, string> $ignoredPackages
     * @param array<string, string> $ignoredPaths
     * @param array<string, string> $ignoredNamespaces
     * @param array<string, string> $ignoredGlobals
     * @param array<string, array<string, string>> $ignoredStrings
     * @param array<string, string> $removedPackages
     * @param array<string, string> $removedPaths
     * @param array<string, list<string>> $removedExcludes
     * @param array<string, array<string, string>> $removedStrings
     * @param array<string, string> $overrideGlobals
     * @param array<string, string> $overrideNamespaces
     */
    private function __construct(public readonly string $vendor, public readonly string $dist, public readonly array $ignoredPackages, public readonly array $ignoredPaths, public readonly array $ignoredNamespaces, public readonly array $ignoredGlobals, public readonly array $ignoredStrings, public readonly array $removedPackages, public readonly array $removedPaths, public readonly array $removedExcludes, public readonly array $removedStrings, public readonly array $overrideGlobals, public readonly array $overrideNamespaces) {}

    /** @param array<string, mixed> $build */
    public static function from(array $build, string $vendor, string $dist): self
    {
        $config = [
            'ignored_packages' => [],
            'ignored_paths' => [],
            'ignored_namespaces' => [],
            'ignored_globals' => [],
            'ignored_strings' => [],
            'removed_packages' => [],
            'removed_paths' => [],
            'removed_excludes' => [],
            'removed_strings' => [],
            'override_globals' => [],
            'override_namespaces' => [],
        ];

        $seen = [];

        foreach (['ignore', 'remove', 'override'] as $section) {
            $entries = $build[$section] ?? [];

            if (!is_array($entries) || (!array_is_list($entries) && $entries !== [])) {
                throw new ScoperException("{$section}: expected a list of entries");
            }

            foreach ($entries as $index => $entry) {
                self::readEntry($config, $seen, $section, (int) $index, $entry, $vendor, $dist);
            }
        }

        return new self($vendor, $dist, $config['ignored_packages'], $config['ignored_paths'], $config['ignored_namespaces'], $config['ignored_globals'], $config['ignored_strings'], $config['removed_packages'], $config['removed_paths'], $config['removed_excludes'], $config['removed_strings'], $config['override_globals'], $config['override_namespaces']);
    }

    /** @return list<string> */
    public function ignoredPaths(): array
    {
        return array_merge(array_map(fn(string $package): string => $this->vendor . '/' . $package, array_keys($this->ignoredPackages)), array_keys($this->ignoredPaths));
    }

    public function isIgnored(string $relative, ?array $patterns = null): bool
    {
        return Glob::isIgnored($relative, $patterns ?? $this->ignoredPaths());
    }

    /**
     * Is a build-relative path gone because the policy removed it — its package, the path itself, or
     * the path minus its exclusions?
     */
    public function isRemoved(string $path): bool
    {
        $path = trim($path, '/');

        foreach (array_keys($this->removedPackages) as $package) {
            if (Glob::isIgnored($path, [$this->vendor . '/' . $package])) {
                return true;
            }
        }

        foreach (array_keys($this->removedPaths) as $pattern) {
            if (!Glob::isIgnored($path, [$pattern])) {
                continue;
            }

            if (!Glob::isExcluded($this->removedExcludes[$pattern] ?? [], $path)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $installed @return list<string> */
    public function packagesMatching(array $installed, string $pattern): array
    {
        return Glob::packagesMatching($installed, $pattern);
    }

    /** @return array{action: string, reason: string, scope: string}|null */
    public function stringRule(string $section, string $package, string $literal): ?array
    {
        $bucket = $section === 'remove' ? 'removedStrings' : 'ignoredStrings';

        if (isset($this->{$bucket}[$package][$literal])) {
            return ['action' => $section, 'reason' => $this->{$bucket}[$package][$literal], 'scope' => $package];
        }

        if (isset($this->{$bucket}['*'][$literal])) {
            return ['action' => $section, 'reason' => $this->{$bucket}['*'][$literal], 'scope' => '*'];
        }

        return null;
    }

    /** @param array<string, mixed> $config @param array<string, string> $seen */
    private static function readEntry(array &$config, array &$seen, string $section, int $index, mixed $entry, string $vendor, string $dist): void
    {
        $where = "{$section}[{$index}]";

        if (!is_array($entry) || array_is_list($entry)) {
            throw new ScoperException("{$where}: expected an object with type, value and reason");
        }

        $unknown = array_diff(array_keys($entry), self::ENTRY_KEYS);

        if ($unknown !== []) {
            throw new ScoperException(sprintf('%s: unknown key(s): %s (allowed: %s)', $where, implode(', ', $unknown), implode(', ', self::ENTRY_KEYS)));
        }

        $type = $entry['type'] ?? null;

        if (!is_string($type) || !in_array($type, self::TYPES[$section], true)) {
            throw new ScoperException(sprintf('%s: "type" must be one of %s', $where, implode('|', self::TYPES[$section])));
        }

        if (!array_key_exists('value', $entry)) {
            throw new ScoperException("{$where}: a \"value\" is required");
        }

        $list = is_array($entry['value']);
        $values = $list ? $entry['value'] : [$entry['value']];
        $label = static fn(int $position): string => $list ? "{$where}.value[{$position}]" : $where;

        if ($values === [] || !array_is_list($values)) {
            throw new ScoperException("{$where}: \"value\" must be a non-empty string or a list of strings");
        }

        foreach ($values as $position => $candidate) {
            if (!is_string($candidate) || trim($candidate) === '') {
                throw new ScoperException("{$label($position)}: \"value\" must be a non-empty string");
            }
        }

        $reason = trim((string) ($entry['reason'] ?? ''));

        if ($reason === '') {
            throw new ScoperException("{$where}: a \"reason\" is required");
        }

        $package = null;

        if (array_key_exists('package', $entry)) {
            if ($type !== 'string' && $type !== 'path') {
                throw new ScoperException("{$where}: \"package\" only applies to string and path entries");
            }

            if (!is_string($entry['package']) || preg_match('#^[a-z0-9_.-]+/[a-z0-9_.-]+$#i', trim($entry['package'], '/')) !== 1) {
                throw new ScoperException("{$where}: \"package\" must be a Composer name like vendor/name");
            }

            $package = trim($entry['package'], '/');
        }

        $exclude = [];

        if (array_key_exists('exclude', $entry)) {
            if ($section !== 'remove' || $type !== 'path') {
                throw new ScoperException("{$where}: \"exclude\" only applies to path entries in remove");
            }

            $patterns = is_array($entry['exclude']) ? $entry['exclude'] : [$entry['exclude']];

            if ($patterns === [] || !array_is_list($patterns)) {
                throw new ScoperException("{$where}: \"exclude\" must be a glob or a list of globs");
            }

            foreach ($patterns as $pattern) {
                if (!is_string($pattern) || trim($pattern) === '') {
                    throw new ScoperException("{$where}: \"exclude\" must not be empty");
                }

                if (str_contains($pattern, '/') || str_contains($pattern, '\\')) {
                    throw new ScoperException("{$where}: \"exclude\" matches the file name only, so it cannot contain a slash");
                }

                $exclude[] = trim($pattern);
            }

            $exclude = array_values(array_unique($exclude));
        }

        foreach ($values as $position => $value) {
            $whereValue = $label($position);
            $scope = '';

            switch ($type) {
                case 'package':
                    $bucket = $section === 'remove' ? 'removed_packages' : 'ignored_packages';
                    $key = trim($value);

                    if (preg_match('#^[a-z0-9_.-]+/[a-z0-9_.*-]+$#i', $key) !== 1) {
                        if (str_contains($key, '*')) {
                            throw new ScoperException("{$whereValue}: a package pattern wildcards the name only (symfony/polyfill-*), not the vendor");
                        }

                        throw new ScoperException("{$whereValue}: \"value\" must be a Composer name like vendor/name");
                    }

                    break;

                case 'namespace':
                    $bucket = $section === 'override' ? 'override_namespaces' : 'ignored_namespaces';
                    $key = trim($value, '\\');
                    break;

                case 'global':
                    $bucket = $section === 'override' ? 'override_globals' : 'ignored_globals';
                    $key = ltrim($value, '\\');
                    break;

                case 'path':
                    $bucket = $section === 'remove' ? 'removed_paths' : 'ignored_paths';
                    $key = self::pathValue($whereValue, $value, $package, $vendor, $dist);
                    break;

                default:
                    $bucket = $section === 'remove' ? 'removed_strings' : 'ignored_strings';
                    $key = $value;
                    $scope = $package ?? '*';
                    break;
            }

            if ($key === '') {
                throw new ScoperException("{$whereValue}: \"value\" is empty after normalization");
            }

            $identity = $bucket . '|' . $scope . '|' . $key;

            if (isset($seen[$identity])) {
                throw new ScoperException(sprintf('%s: duplicate of %s ("%s")', $whereValue, $seen[$identity], $key));
            }

            $seen[$identity] = $whereValue;

            if ($type === 'string') {
                $config[$bucket][$scope][$key] = $reason;
            } else {
                $config[$bucket][$key] = $reason;
            }

            if ($exclude !== []) {
                $config['removed_excludes'][$key] = $exclude;
            }
        }
    }

    /**
     * A path value is build-relative, or package-relative when `package` is given. `{vendor}` and
     * `{dist}` expand to the configured directories, so a policy stays portable across projects.
     */
    private static function pathValue(string $where, string $value, ?string $package, string $vendor, string $dist): string
    {
        $value = str_replace(['{vendor}', '{dist}', '\\'], [$vendor, $dist, '/'], $value);
        $value = trim($value, '/');

        if ($package !== null) {
            $value = trim($vendor . '/' . $package . '/' . $value, '/');
        }

        if (!str_starts_with($value, $vendor . '/') && !str_starts_with($value, $dist . '/')) {
            throw new ScoperException(sprintf('%s: a path must be under %s/ or %s/ (the project\'s own code is never a policy subject)', $where, $vendor, $dist));
        }

        return Path::normalize($value);
    }
}
