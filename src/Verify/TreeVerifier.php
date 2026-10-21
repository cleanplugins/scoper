<?php

declare(strict_types=1);

namespace CleanPlugins\Scoper\Verify;

use CleanPlugins\Scoper\Config\Config;
use CleanPlugins\Scoper\Exception\ScoperException;
use CleanPlugins\Scoper\Support\Filesystem;

/**
 * Verifies that the staged tree equals the snapshot plus exactly the mutations the build recorded.
 *
 * The snapshot is captured before the destructive phases. Removals are verified and folded into the
 * snapshot first; scoping is then verified against the post-removal baseline. Anything else that
 * changed — in vendor or in the project's own files — fails the build.
 */
final class TreeVerifier
{
    public function __construct(private readonly Config $config, private readonly Filesystem $files) {}

    /**
     * @param list<string> $deleted
     * @param array<string, string> $rewritten
     */
    public function afterRemovals(array $deleted, array $rewritten, ?callable $tick = null): void
    {
        $this->verify('removals', [], $deleted, $rewritten, [], $tick);

        (new Snapshot($this->config, $this->files))->apply($deleted, $rewritten);
    }

    /** @param array<string, list<array{start: int, end: int, from: string, to: string}>> $edits */
    public function afterScope(array $edits, ?callable $tick = null): int
    {
        return $this->verify('scoping', $edits, [], [], [$this->config->vendor . '/autoload.php'], $tick);
    }

    /**
     * @param array<string, list<array{start: int, end: int, from: string, to: string}>> $edits
     * @param list<string> $deleted
     * @param array<string, string> $rewritten
     * @param list<string> $added
     */
    private function verify(string $stage, array $edits, array $deleted, array $rewritten, array $added, ?callable $tick = null): int
    {
        $snapshotDir = $this->config->snapshotPath();
        $buildDir = $this->config->outputPath();

        if (!is_dir($snapshotDir)) {
            throw new ScoperException("Build snapshot is missing: {$snapshotDir}");
        }

        $snapshotFiles = $this->files->relativeFiles($snapshotDir, $snapshotDir);
        $buildFiles = $this->files->relativeFiles($buildDir, $buildDir);
        $snapshotSet = array_fill_keys($snapshotFiles, true);
        $buildSet = array_fill_keys($buildFiles, true);
        $deletedSet = array_fill_keys($deleted, true);
        $addedSet = array_fill_keys($added, true);
        $failures = [];

        foreach ($snapshotFiles as $relative) {
            if ($tick !== null) {
                $tick();
            }

            if (!isset($buildSet[$relative])) {
                if (!isset($deletedSet[$relative])) {
                    $failures[] = "{$relative} is missing from the build but was not removed";
                }

                continue;
            }

            $expectedPath = $snapshotDir . '/' . $relative;
            $actualPath = $buildDir . '/' . $relative;

            if (isset($edits[$relative])) {
                $expected = $this->apply($this->files->read($expectedPath), $edits[$relative]);

                if ($expected !== $this->files->read($actualPath)) {
                    $failures[] = "{$relative} does not match the recorded edits";
                }

                continue;
            }

            if (isset($rewritten[$relative])) {
                if (!hash_equals($rewritten[$relative], (string) hash_file('sha256', $actualPath))) {
                    $failures[] = "{$relative} does not match the recorded rewrite";
                }

                continue;
            }

            if (!hash_equals((string) hash_file('sha256', $expectedPath), (string) hash_file('sha256', $actualPath))) {
                $failures[] = "{$relative} was modified without a recorded edit";
            }
        }

        foreach ($buildFiles as $relative) {
            if ($tick !== null) {
                $tick();
            }

            if (!isset($snapshotSet[$relative]) && !isset($addedSet[$relative])) {
                $failures[] = "{$relative} was added without a recorded addition";
            }
        }

        foreach ($deleted as $relative) {
            if ($tick !== null) {
                $tick();
            }

            if (isset($buildSet[$relative])) {
                $failures[] = "{$relative} was recorded as removed but is still present";
            }
        }

        if ($failures !== []) {
            throw new ScoperException(sprintf('The staged tree does not match the snapshot after %s', $stage), array_slice($failures, 0, 40));
        }

        return count($snapshotFiles) + count(array_intersect($added, $buildFiles));
    }

    /** @param list<array{start: int, end: int, from: string, to: string}> $edits */
    private function apply(string $source, array $edits): string
    {
        usort($edits, static fn(array $a, array $b): int => $b['start'] <=> $a['start']);

        foreach ($edits as $edit) {
            $source = substr_replace($source, $edit['to'], $edit['start'], $edit['end'] - $edit['start'] + 1);
        }

        return $source;
    }
}
