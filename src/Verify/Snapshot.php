<?php

declare(strict_types=1);

namespace CleanPlugins\Scoper\Verify;

use CleanPlugins\Scoper\Config\Config;
use CleanPlugins\Scoper\Support\Filesystem;

/**
 * A copy of the staged tree taken before the destructive phases. It stays in the artifacts
 * directory for verification and post-mortem diffing, and is replaced by the next build.
 */
final class Snapshot
{
    public function __construct(private readonly Config $config, private readonly Filesystem $files) {}

    public function capture(?callable $tick = null): void
    {
        $this->files->remove($this->config->snapshotPath());
        $this->files->copy($this->config->outputPath(), $this->config->snapshotPath(), $tick);
    }

    /**
     * Bring the snapshot up to date after a verified phase so the next phase has the right baseline.
     *
     * @param list<string> $deleted
     * @param array<string, string> $rewritten
     */
    public function apply(array $deleted, array $rewritten): void
    {
        $snapshot = $this->config->snapshotPath();

        foreach ($deleted as $relative) {
            $this->files->remove($snapshot . '/' . $relative);
        }

        foreach (array_keys($rewritten) as $relative) {
            $this->files->copy($this->config->outputPath() . '/' . $relative, $snapshot . '/' . $relative);
        }
    }
}
