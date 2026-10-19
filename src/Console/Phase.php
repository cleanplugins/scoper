<?php

declare(strict_types=1);

namespace CleanPlugins\Scoper\Console;

use CleanPlugins\Scoper\Support\Progress;

final class Phase implements Progress
{
    private float $started;

    private bool $done = false;

    public function __construct(private readonly Reporter $reporter, private readonly string $title)
    {
        $this->started = microtime(true);
        $this->reporter->beginPhase($this->title);
    }

    public function total(int $steps): void
    {
        $this->reporter->progressTotal($steps);
    }

    public function advance(int $steps = 1): void
    {
        $this->reporter->progressAdvance($steps);
    }

    public function end(string $detail = ''): void
    {
        $this->settle(true, $detail);
    }

    public function fail(string $detail = ''): void
    {
        $this->settle(false, $detail);
    }

    private function settle(bool $ok, string $detail): void
    {
        if ($this->done) {
            return;
        }

        $this->done = true;
        $this->reporter->endPhase($this->title, $ok, $detail, (microtime(true) - $this->started) * 1000);
    }
}
