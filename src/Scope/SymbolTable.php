<?php

declare(strict_types=1);

namespace CleanPlugins\Scoper\Scope;

use CleanPlugins\Scoper\Config\Prefix;

/**
 * What is ours to rename, what is protected, and how a name resolves.
 *
 * Protection wins over declaration, so an excluded package's namespaces and the project's own are
 * never rewritten even when another package declares the same root.
 */
final class SymbolTable
{
    /** @var list<string> */
    private array $declaredNamespaceList;

    /** @var list<string> */
    private array $protectedNamespaceList;

    /**
     * @param array<string, array<string, mixed>> $declaredNamespaces
     * @param list<string> $protectedNamespaces
     * @param array<string, array<string, mixed>> $declaredGlobals
     * @param array<string, string> $protectedGlobals
     */
    public function __construct(private readonly Prefix $prefix, private readonly array $declaredNamespaces, array $protectedNamespaces, private readonly array $declaredGlobals, private readonly array $protectedGlobals)
    {
        $this->declaredNamespaceList = self::sorted($declaredNamespaces === [] ? [] : array_keys($declaredNamespaces));
        $this->protectedNamespaceList = self::sorted($protectedNamespaces);
    }

    public function resolve(string $value): ?string
    {
        if ($this->match($value, $this->protectedNamespaceList) !== null || isset($this->protectedGlobals[$value])) {
            return null;
        }

        if ($this->match($value, $this->declaredNamespaceList) !== null) {
            return $this->prefix->namespace . '\\' . $value;
        }

        if (isset($this->declaredGlobals[$value])) {
            return (string) $this->declaredGlobals[$value]['to'];
        }

        return null;
    }

    public function symbolIshPrefix(string $value): bool
    {
        $value = ltrim($value, '\\');

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $value) !== 1 || (strlen($value) < 6 && !str_contains($value, '_') && !str_contains($value, '\\'))) {
            return false;
        }

        foreach (array_keys($this->declaredNamespaces) as $namespace) {
            if (str_starts_with((string) $namespace, $value)) {
                return true;
            }
        }

        foreach (array_merge(array_keys($this->declaredGlobals), array_keys($this->protectedGlobals)) as $symbol) {
            if (str_starts_with((string) $symbol, $value)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, array<string, mixed>> */
    public function declaredNamespaces(): array
    {
        return $this->declaredNamespaces;
    }

    /** @return array<string, array<string, mixed>> */
    public function declaredGlobals(): array
    {
        return $this->declaredGlobals;
    }

    /** @return array<string, string> */
    public function protectedGlobals(): array
    {
        return $this->protectedGlobals;
    }

    /** @param list<string> $prefixes */
    private function match(string $value, array $prefixes): ?string
    {
        foreach ($prefixes as $candidate) {
            if (strncasecmp($value, $candidate, strlen($candidate)) !== 0) {
                continue;
            }

            if (strlen($value) === strlen($candidate) || $value[strlen($candidate)] === '\\') {
                return $candidate;
            }
        }

        return null;
    }

    /** @param list<string> $namespaces @return list<string> */
    private static function sorted(array $namespaces): array
    {
        $namespaces = array_values(array_unique(array_filter($namespaces, static fn(string $namespace): bool => $namespace !== '')));
        usort($namespaces, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

        return $namespaces;
    }
}
