<?php

declare(strict_types=1);

namespace CleanPlugins\Scoper\Support;

/**
 * A phase can report a real total and advance through it. Phases that cannot calculate a total
 * simply never call `total()`; they keep the busy label with its spinner and elapsed time.
 */
interface Progress
{
    public function total(int $steps): void;

    public function advance(int $steps = 1): void;
}
