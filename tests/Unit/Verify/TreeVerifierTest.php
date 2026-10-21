<?php

declare(strict_types=1);

namespace CleanPlugins\Scoper\Tests\Unit\Verify;

use CleanPlugins\Scoper\Exception\ScoperException;
use CleanPlugins\Scoper\Support\Filesystem;
use CleanPlugins\Scoper\Tests\TestCase;
use CleanPlugins\Scoper\Verify\Snapshot;
use CleanPlugins\Scoper\Verify\TreeVerifier;

final class TreeVerifierTest extends TestCase
{
    public function testAcceptsAnUnchangedTree(): void
    {
        $config = $this->config();
        $this->snapshot(['src/a.php' => 'alpha', 'src/b.php' => 'beta']);
        $this->write('build/src/a.php', 'alpha');
        $this->write('build/src/b.php', 'beta');

        $verifier = new TreeVerifier($config, new Filesystem($this->tmp));
        $ticks = 0;

        self::assertSame(2, $verifier->afterScope([], function () use (&$ticks): void {
            ++$ticks;
        }));
        self::assertGreaterThan(0, $ticks);
    }

    public function testRejectsAnUnrecordedModification(): void
    {
        $config = $this->config();
        $this->snapshot(['src/a.php' => 'alpha']);
        $this->write('build/src/a.php', 'changed');

        $this->expectException(ScoperException::class);
        $this->expectExceptionMessage('does not match the snapshot');

        (new TreeVerifier($config, new Filesystem($this->tmp)))->afterScope([]);
    }

    public function testAcceptsARecordedEdit(): void
    {
        $config = $this->config();
        $this->snapshot(['src/a.php' => 'abcDEFghi']);
        $this->write('build/src/a.php', 'abcXYZghi');

        $verifier = new TreeVerifier($config, new Filesystem($this->tmp));

        self::assertSame(1, $verifier->afterScope([
            'src/a.php' => [['start' => 3, 'end' => 5, 'from' => 'DEF', 'to' => 'XYZ']],
        ]));
    }

    public function testRejectsAnEditThatDoesNotMatchTheRecordedRange(): void
    {
        $config = $this->config();
        $this->snapshot(['src/a.php' => 'abcDEFghi']);
        $this->write('build/src/a.php', 'abcQQQghi');

        $this->assertFailure('does not match the recorded edits', fn() => (new TreeVerifier($config, new Filesystem($this->tmp)))->afterScope([
            'src/a.php' => [['start' => 3, 'end' => 5, 'from' => 'DEF', 'to' => 'XYZ']],
        ]));
    }

    public function testAcceptsRecordedRemovalsAndUpdatesTheSnapshot(): void
    {
        $config = $this->config();
        $this->snapshot(['src/a.php' => 'alpha', 'src/b.php' => 'beta']);
        $this->write('build/src/b.php', 'beta');

        $verifier = new TreeVerifier($config, new Filesystem($this->tmp));
        $verifier->afterRemovals(['src/a.php'], []);

        self::assertFileDoesNotExist($this->tmp . '/.build/snapshot/src/a.php');
        self::assertSame(1, $verifier->afterScope([]));
    }

    public function testAcceptsRecordedRewritesAndUpdatesTheSnapshot(): void
    {
        $config = $this->config();
        $this->snapshot(['src/a.php' => 'alpha']);
        $this->write('build/src/a.php', 'omega');

        $verifier = new TreeVerifier($config, new Filesystem($this->tmp));
        $verifier->afterRemovals([], ['src/a.php' => hash('sha256', 'omega')]);

        self::assertSame('omega', (string) file_get_contents($this->tmp . '/.build/snapshot/src/a.php'));
        self::assertSame(1, $verifier->afterScope([]));
    }

    public function testRejectsARecordedRewriteWithTheWrongHash(): void
    {
        $config = $this->config();
        $this->snapshot(['src/a.php' => 'alpha']);
        $this->write('build/src/a.php', 'omega');

        $this->assertFailure('does not match the recorded rewrite', fn() => (new TreeVerifier($config, new Filesystem($this->tmp)))->afterRemovals([], ['src/a.php' => hash('sha256', 'wrong')]));
    }

    public function testRejectsAnUnrecordedRemoval(): void
    {
        $config = $this->config();
        $this->snapshot(['src/a.php' => 'alpha', 'src/b.php' => 'beta']);
        $this->write('build/src/b.php', 'beta');

        $this->assertFailure('is missing from the build but was not removed', fn() => (new TreeVerifier($config, new Filesystem($this->tmp)))->afterRemovals([], []));
    }

    public function testRejectsARecordedRemovalThatIsStillPresent(): void
    {
        $config = $this->config();
        $this->snapshot(['src/a.php' => 'alpha']);
        $this->write('build/src/a.php', 'alpha');

        $this->assertFailure('recorded as removed but is still present', fn() => (new TreeVerifier($config, new Filesystem($this->tmp)))->afterRemovals(['src/a.php'], []));
    }

    public function testAllowsOnlyTheRecordedAddition(): void
    {
        $config = $this->config();
        $this->snapshot(['src/a.php' => 'alpha']);
        $this->write('build/src/a.php', 'alpha');
        $this->write('build/vendor/autoload.php', 'loader');

        $verifier = new TreeVerifier($config, new Filesystem($this->tmp));
        self::assertSame(2, $verifier->afterScope([]));

        $this->write('build/src/extra.php', 'extra');

        $this->assertFailure('was added without a recorded addition', fn() => $verifier->afterScope([]));
    }

    public function testSnapshotCaptureIncludesTheStagedTree(): void
    {
        $config = $this->config();
        $this->write('build/src/a.php', 'alpha');
        $ticks = 0;

        (new Snapshot($config, new Filesystem($this->tmp)))->capture(function () use (&$ticks): void {
            ++$ticks;
        });

        self::assertFileExists($this->tmp . '/.build/snapshot/src/a.php');
        self::assertSame('alpha', (string) file_get_contents($this->tmp . '/.build/snapshot/src/a.php'));
        self::assertGreaterThan(0, $ticks);
    }

    public function testSnapshotCaptureReplacesThePreviousSnapshot(): void
    {
        $config = $this->config();
        $this->write('.build/snapshot/stale.php', 'stale');
        $this->write('build/src/a.php', 'alpha');

        (new Snapshot($config, new Filesystem($this->tmp)))->capture();

        self::assertFileDoesNotExist($this->tmp . '/.build/snapshot/stale.php');
        self::assertSame('alpha', (string) file_get_contents($this->tmp . '/.build/snapshot/src/a.php'));
    }

    private function assertFailure(string $needle, callable $callback): void
    {
        try {
            $callback();
        } catch (ScoperException $exception) {
            self::assertStringContainsString($needle, implode("\n", $exception->details));

            return;
        }

        self::fail('Expected the build to fail');
    }

    /** @param array<string, string> $files */
    private function snapshot(array $files): void
    {
        foreach ($files as $relative => $contents) {
            $this->write('.build/snapshot/' . $relative, $contents);
        }
    }
}
