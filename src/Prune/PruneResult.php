<?php

declare(strict_types=1);

namespace CleanPlugins\Scoper\Prune;

final class PruneResult
{
    /**
     * @param list<array<string, mixed>> $sections
     * @param list<string> $warnings
     * @param list<string> $unused
     * @param list<string> $deleted build-relative files removed by the policy
     * @param array<string, string> $rewritten build-relative files whose literals were emptied, with their new sha256
     */
    public function __construct(public readonly array $sections, public readonly array $warnings, public readonly array $unused, public readonly array $deleted, public readonly array $rewritten, public readonly int $packages, public readonly int $packagePatterns, public readonly int $patterns, public readonly int $literals, public readonly int $files, public readonly int $excluded) {}
}
