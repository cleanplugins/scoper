<?php

declare(strict_types=1);

namespace CleanPlugins\Scoper\Build;

final class BuildResult
{
    public function __construct(public readonly string $hash, public readonly int $files, public readonly int $size, public readonly string $mapPath, public readonly ?string $releasePath = null, public readonly int $releaseEntries = 0, public readonly int $releaseSize = 0) {}
}
