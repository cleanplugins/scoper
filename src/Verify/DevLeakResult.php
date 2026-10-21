<?php

declare(strict_types=1);

namespace CleanPlugins\Scoper\Verify;

final class DevLeakResult
{
    /**
     * @param list<string> $leaks
     */
    public function __construct(public readonly array $leaks, public readonly int $files, public readonly int $packages, public readonly int $symbols) {}
}
