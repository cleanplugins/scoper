<?php

declare(strict_types=1);

namespace CleanPlugins\Scoper\Verify;

final class VerifyResult
{
    /**
     * @param list<string> $failures
     * @param list<string> $lines
     */
    public function __construct(public readonly int $checks, public readonly array $failures, public readonly array $lines, public readonly int $ownFiles) {}
}
