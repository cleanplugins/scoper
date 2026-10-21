<?php

declare(strict_types=1);

namespace CleanPlugins\Scoper\Tests\Unit\Build;

use CleanPlugins\Scoper\Build\Builder;
use CleanPlugins\Scoper\Console\Reporter;
use CleanPlugins\Scoper\Support\Filesystem;
use CleanPlugins\Scoper\Tests\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

final class BuilderTest extends TestCase
{
    public function testASnapshotLeftByAnEarlierBuildIsNotReported(): void
    {
        $this->write('.build/snapshot/src/a.php', 'alpha');

        $builder = new Builder($this->config(), new Reporter(new BufferedOutput()), new Filesystem($this->tmp));

        self::assertNull($builder->capturedSnapshot());
    }
}
