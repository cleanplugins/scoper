<?php

declare(strict_types=1);

namespace CleanPlugins\Scoper\Tests\Unit\Console;

use CleanPlugins\Scoper\Console\Reporter;
use CleanPlugins\Scoper\Tests\TestCase;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

final class ReporterTest extends TestCase
{
    public function testMissingEntryWarningsAreHiddenWithoutVerbose(): void
    {
        $output = new BufferedOutput();
        $reporter = new Reporter($output);

        $phase = $reporter->phase('Removals');
        $reporter->warn('path "vendor/**/*.md" matched nothing', true);
        $phase->end('0 path patterns');

        self::assertStringNotContainsString('matched nothing', $output->fetch());
    }

    public function testVerboseWarningsFollowThePhaseTitle(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
        $reporter = new Reporter($output);

        $phase = $reporter->phase('Removals');
        $reporter->warn('path "vendor/**/*.md" matched nothing', true);
        $phase->end('0 path patterns');

        $text = $output->fetch();
        $title = strpos($text, 'Removals');
        $detail = strpos($text, '0 path patterns');
        $warning = strpos($text, 'matched nothing');

        self::assertNotFalse($title);
        self::assertNotFalse($detail);
        self::assertNotFalse($warning);
        self::assertGreaterThan($title, $detail);
        self::assertGreaterThan($detail, $warning);
    }

    public function testOperationalWarningsFollowThePhaseTitle(): void
    {
        $output = new BufferedOutput();
        $reporter = new Reporter($output);

        $phase = $reporter->phase('Prerequisites');
        $reporter->warn('Xdebug is loaded');
        $phase->end('prefix Vendor\\Build\\');

        $text = $output->fetch();

        self::assertGreaterThan((int) strpos($text, 'Prerequisites'), (int) strpos($text, 'Xdebug is loaded'));
    }

    public function testMarksAreDistinctWithoutDecoration(): void
    {
        $output = new BufferedOutput();
        $reporter = new Reporter($output);

        $reporter->phase('Clean output')->end('build/ is empty');
        $reporter->phase('Assets')->fail('asset build failed');

        $phase = $reporter->phase('Removals');
        $reporter->warn('path "vendor/**/*.md" matched nothing');
        $phase->end('0 path patterns');

        $text = $output->fetch();

        self::assertStringContainsString('+ Clean output', $text);
        self::assertStringContainsString('x Assets', $text);
        self::assertStringContainsString('  ! path "vendor/**/*.md" matched nothing', $text);
        self::assertStringNotContainsString('✔', $text);
        self::assertStringNotContainsString('✖', $text);
    }

    public function testFailureMarkIsRedWithoutTheErrorBackground(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
        $reporter = new Reporter($output);

        $reporter->phase('Assets')->fail('asset build failed');
        $reporter->fatal(new RuntimeException('asset build failed'));

        $text = $output->fetch();

        self::assertStringContainsString("\033[31;1m✖", $text);
        self::assertStringNotContainsString(';41m', $text);
    }

    public function testQuietModeOnlyPrintsPhaseTitles(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_QUIET);
        $reporter = new Reporter($output);

        $phase = $reporter->phase('Prerequisites');
        $reporter->warn('Xdebug is loaded');
        $reporter->warn('path "vendor/**/*.md" matched nothing', true);
        $phase->end('prefix Vendor\\Build\\');

        $text = $output->fetch();

        self::assertStringContainsString('Prerequisites', $text);
        self::assertStringNotContainsString('Xdebug is loaded', $text);
        self::assertStringNotContainsString('matched nothing', $text);
    }
}
