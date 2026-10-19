<?php

declare(strict_types=1);

namespace CleanPlugins\Scoper\Verify;

final class ScanResult
{
    /** @param list<string> $leaks */
    public function __construct(public readonly int $files, public readonly array $leaks) {}
}
