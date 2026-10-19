<?php

declare(strict_types=1);

namespace CleanPlugins\Scoper\Prune;

final class PruneResult
{
    /**
     * @param list<array<string, mixed>> $sections
     * @param list<string> $warnings
     */
    public function __construct(public readonly array $sections, public readonly array $warnings, public readonly int $packages, public readonly int $packagePatterns, public readonly int $patterns, public readonly int $literals, public readonly int $files, public readonly int $excluded) {}
}
