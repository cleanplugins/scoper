<?php

declare(strict_types=1);

namespace CleanPlugins\Scoper\Autoload;

final class AutoloadResult
{
    /**
     * @param list<array<string, mixed>> $sections
     * @param list<string> $warnings
     */
    public function __construct(public readonly array $sections, public readonly array $warnings, public readonly int $classes) {}
}
