<?php

declare(strict_types=1);

namespace CleanPlugins\Scoper\Scope;

final class ScopeResult
{
    /**
     * @param array<string, mixed> $map
     * @param list<array<string, mixed>> $sections
     * @param list<string> $warnings
     */
    public function __construct(public readonly array $map, public readonly array $sections, public readonly array $warnings, public readonly int $namespaces, public readonly int $globals, public readonly int $edits, public readonly int $filesScanned, public readonly int $filesRewritten, public readonly string $mapPath) {}
}
