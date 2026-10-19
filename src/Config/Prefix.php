<?php

declare(strict_types=1);

namespace CleanPlugins\Scoper\Config;

use CleanPlugins\Scoper\Exception\ScoperException;

/**
 * The namespace prefix and the five rendered global prefixes.
 *
 * `prefix` is a namespace string or:
 *
 *     { "namespace": "Vendor\\Package", "global": "VendorPackage" }
 *     { "namespace": "Vendor\\Package", "global": { "base": "VendorPackage", "snake_case": "vendor_package_" } }
 *
 * A style resolves from its own key, then from `base`, then from the namespace. Derivation is a
 * heuristic: `VendorPackage` splits into `Vendor` and `Package`, an acronym splits before its last
 * capital (`SDKClient` → `SDK_CLIENT_`), and an explicit style key wins when the guess is wrong.
 */
final class Prefix
{
    public const STYLES = ['screaming_snake', 'snake_pascal', 'snake_case', 'pascal', 'camel'];

    /** @param array<string, string> $globals */
    private function __construct(public readonly string $namespace, public readonly array $globals) {}

    public static function from(mixed $prefix): self
    {
        $styles = [];

        if (is_string($prefix)) {
            $namespace = trim($prefix, '\\');
            $base = null;
        } elseif (is_array($prefix)) {
            $unknown = array_diff(array_keys($prefix), ['namespace', 'global']);

            if ($unknown !== []) {
                throw new ScoperException('unknown key(s) under prefix: ' . implode(', ', $unknown));
            }

            $namespace = trim((string) ($prefix['namespace'] ?? ''), '\\');
            $global = $prefix['global'] ?? null;
            $base = is_string($global) ? $global : (is_array($global) ? $global['base'] ?? null : null);
            $styles = is_array($global) ? $global : [];

            $unknown = array_diff(array_keys($styles), array_merge(['base'], self::STYLES));

            if ($unknown !== []) {
                throw new ScoperException('unknown key(s) under prefix.global: ' . implode(', ', $unknown));
            }
        } else {
            throw new ScoperException('"prefix" must be a string or an object');
        }

        if ($namespace === '') {
            throw new ScoperException('"prefix" (or prefix.namespace) must be a non-empty namespace');
        }

        $namespaceBase = implode('', self::segments($namespace));
        $derived = self::derive($base !== null ? (string) $base : $namespaceBase);
        $prefixes = [];

        foreach (self::STYLES as $style) {
            $explicit = $styles[$style] ?? null;
            $prefixes[$style] = $explicit !== null ? (string) $explicit : $derived[$style];
        }

        foreach ($prefixes as $style => $value) {
            if ($value === '' || preg_match('/[\\\\\s]/', $value) === 1) {
                throw new ScoperException("global prefix \"{$style}\" must be a non-empty identifier fragment (no backslashes)");
            }
        }

        return new self($namespace, $prefixes);
    }

    /** The case style of an identifier, which decides which prefix form is rendered for it. */
    public function style(string $name): string
    {
        $core = ltrim($name, '_');

        if (preg_match('/^[A-Z][A-Z0-9_]*$/', $core) === 1) {
            return 'screaming_snake';
        }

        if (str_contains($core, '_')) {
            return preg_match('/^[a-z]/', $core) === 1 ? 'snake_case' : 'snake_pascal';
        }

        if (preg_match('/^[A-Z]/', $core) === 1) {
            return 'pascal';
        }

        return preg_match('/[A-Z]/', $core) === 1 ? 'camel' : 'snake_case';
    }

    public function render(string $symbol): string
    {
        return $this->globals[$this->style($symbol)] . $symbol;
    }

    /** @return list<string> */
    private static function segments(string $value): array
    {
        $parts = preg_split('/[\\\\_\s]+|(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', $value) ?: [];
        $parts = array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));

        return $parts === [] ? [$value] : $parts;
    }

    /** @return array<string, string> */
    private static function derive(string $base): array
    {
        $segments = self::segments($base);
        $camel = $segments;
        $first = array_shift($camel);
        $first = strtoupper((string) $first) === $first ? strtolower((string) $first) : lcfirst((string) $first);

        return [
            'screaming_snake' => strtoupper(implode('_', $segments)) . '_',
            'snake_pascal' => implode('_', $segments) . '_',
            'snake_case' => strtolower(implode('_', $segments)) . '_',
            'pascal' => implode('', $segments),
            'camel' => $first . implode('', $camel),
        ];
    }
}
