<?php

declare(strict_types=1);

namespace CleanPlugins\Scoper\Console;

use CleanPlugins\Scoper\Exception\ScoperException;
use CleanPlugins\Scoper\Support\Format;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\ProgressIndicator;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * A compact, unambiguous build report:
 *
 *     scoper · build · PHP 8.3.30
 *
 *     ✔ Prerequisites in 0.00s · prefix Vendor\Package\
 *
 *     ✔ Removals in 0.31s · 20 files · 4 patterns · 2 kept
 *
 * With -v the detail moves below the title, above the aligned detail sections:
 *
 *     ✔ Removals in 0.31s
 *       20 files · 4 patterns · 2 kept
 *
 *       Paths
 *       - vendor path …  17 files (2 excluded)
 *
 * Tables align their right column with spaces.
 */
final class Reporter
{
    private float $started;

    private bool $firstPhase = true;

    private ?ProgressBar $bar = null;

    private ?ProgressIndicator $indicator = null;

    private ?string $phaseTitle = null;

    /** @var list<array{message: string, verbose: bool}> */
    private array $pending = [];

    public function __construct(private readonly OutputInterface $output)
    {
        $this->started = microtime(true);
    }

    public function output(): OutputInterface
    {
        return $this->output;
    }

    public function isVerbose(): bool
    {
        return $this->output->isVerbose();
    }

    public function intro(string $title): void
    {
        $this->write('<options=bold>' . OutputFormatter::escape($title) . '</> · <fg=gray>PHP ' . PHP_VERSION . '</>');
        $this->write('');
    }

    public function phase(string $title): Phase
    {
        return new Phase($this, $title);
    }

    public function beginPhase(string $title): void
    {
        if (!$this->firstPhase) {
            $this->write('');
        }

        $this->firstPhase = false;
        $this->phaseTitle = $title;
        $this->pending = [];

        if (!$this->canAnimate()) {
            return;
        }

        $this->indicator = new ProgressIndicator($this->progressOutput(), 'verbose');
        $this->indicator->start($title);
    }

    /** Switch the busy label to a real progress bar once the phase knows its total. */
    public function progressTotal(int $steps): void
    {
        if (!$this->canAnimate() || $steps <= 0) {
            return;
        }

        $this->clearIndicator();

        $this->bar = new ProgressBar($this->progressOutput(), $steps);
        $this->bar->setFormat(' %message%  %current%/%max% [%bar%] %percent:3s%%');
        $this->bar->setMessage((string) $this->phaseTitle);
        $this->bar->start();
    }

    public function progressAdvance(int $steps = 1): void
    {
        if ($this->bar !== null) {
            $this->bar->advance($steps);

            return;
        }

        $this->indicator?->advance();
    }

    public function endPhase(string $title, bool $ok, string $detail, float $milliseconds): void
    {
        $this->finishProgress();

        $mark = $this->mark($ok ? 'ok' : 'fail');
        $titleStyle = $ok ? '<fg=green;options=bold>' : '<fg=red;options=bold>';

        $line = $mark . ' ' . $titleStyle . OutputFormatter::escape($title) . '</> <fg=gray>in ' . Format::duration($milliseconds) . '</>';

        if ($detail !== '' && !$this->isVerbose()) {
            $line .= ' <fg=gray>·</> ' . OutputFormatter::escape($detail);
        }

        $this->write($line, OutputInterface::VERBOSITY_QUIET);

        if ($detail !== '' && $this->isVerbose()) {
            $this->write('  ' . OutputFormatter::escape($detail), OutputInterface::VERBOSITY_QUIET);
        }

        $this->flushWarnings();
    }

    /**
     * Warnings are held until the phase title is on screen, so they never interleave with the
     * spinner or progress bar. Missing policy entries are only reported with -v.
     */
    public function warn(string $message, bool $verboseOnly = false): void
    {
        if ($this->phaseTitle !== null) {
            $this->pending[] = [$message, $verboseOnly];

            return;
        }

        $this->writeWarning($message, $verboseOnly);
    }

    /** @param list<array<string, mixed>> $sections */
    public function render(array $sections): void
    {
        if (!$this->isVerbose()) {
            return;
        }

        $inText = false;

        foreach ($sections as $section) {
            $type = (string) ($section['type'] ?? 'table');

            if ($type === 'text') {
                if (!$inText) {
                    $this->write('');
                    $inText = true;
                }

                $this->write('  ' . OutputFormatter::escape((string) ($section['text'] ?? '')));

                continue;
            }

            $inText = false;

            if ($type === 'list') {
                $items = $section['items'] ?? [];

                if ($items === []) {
                    continue;
                }

                $this->write('');
                $this->write('  <fg=cyan;options=bold>' . OutputFormatter::escape((string) ($section['title'] ?? '')) . '</>');

                foreach ($items as $item) {
                    $this->write('  - ' . OutputFormatter::escape((string) $item));
                }

                continue;
            }

            $rows = $section['rows'] ?? [];

            if ($rows === []) {
                continue;
            }

            $leftWidth = max(array_map(static fn(array $row): int => strlen((string) $row[0]), $rows));

            $this->write('');
            $this->write('  <fg=cyan;options=bold>' . OutputFormatter::escape((string) ($section['title'] ?? '')) . '</>');

            foreach ($rows as $row) {
                $left = str_pad((string) $row[0], $leftWidth);
                $right = isset($row[1]) ? (string) $row[1] : '';

                $this->write('  - ' . OutputFormatter::escape($left) . ($right === '' ? '' : ' ' . '<fg=gray>' . OutputFormatter::escape($right) . '</>'));
            }
        }
    }

    /** @param list<array{0: string, 1: string}> $rows */
    public function summary(array $rows, string $label): void
    {
        $this->write('');
        $this->write($this->mark('ok') . ' <fg=green;options=bold>' . OutputFormatter::escape($label) . '</> <fg=gray>in ' . Format::duration((microtime(true) - $this->started) * 1000) . '</>', OutputInterface::VERBOSITY_QUIET);

        if ($rows !== []) {
            $this->write('', OutputInterface::VERBOSITY_QUIET);
            $keyWidth = max(array_map(static fn(array $row): int => strlen($row[0]), $rows));

            foreach ($rows as [$key, $value]) {
                $this->write('  <fg=gray>' . OutputFormatter::escape(str_pad($key, $keyWidth)) . '</>  ' . OutputFormatter::escape($value), OutputInterface::VERBOSITY_QUIET);
            }
        }

        $this->write('', OutputInterface::VERBOSITY_QUIET);
    }

    public function fatal(Throwable $error, ?string $logPath = null, ?string $snapshotPath = null): void
    {
        $this->finishProgress(true);
        $this->pending = [];

        $message = $error->getMessage();
        $details = $error instanceof ScoperException ? $error->details : [];

        $this->write('');
        $this->write($this->mark('fail') . ' <fg=red;options=bold>Build failed</> <fg=gray>after ' . Format::duration((microtime(true) - $this->started) * 1000) . '</>', OutputInterface::VERBOSITY_QUIET);
        $this->write('', OutputInterface::VERBOSITY_QUIET);

        foreach (preg_split('/\r?\n/', $message) ?: [] as $line) {
            $this->write('  ' . OutputFormatter::escape(rtrim($line)), OutputInterface::VERBOSITY_QUIET);
        }

        if ($details !== []) {
            $this->write('', OutputInterface::VERBOSITY_QUIET);

            foreach (array_slice($details, -30) as $line) {
                $this->write('  - ' . OutputFormatter::escape($line), OutputInterface::VERBOSITY_QUIET);
            }
        }

        if ($logPath !== null || $snapshotPath !== null) {
            $this->write('', OutputInterface::VERBOSITY_QUIET);

            if ($logPath !== null) {
                $this->write('  <fg=gray>captured output</>  ' . OutputFormatter::escape($logPath), OutputInterface::VERBOSITY_QUIET);
            }

            if ($snapshotPath !== null) {
                $this->write('  <fg=gray>snapshot</>         ' . OutputFormatter::escape($snapshotPath), OutputInterface::VERBOSITY_QUIET);
            }
        }

        $this->write('  <fg=gray>rerun</>            composer scoper:build', OutputInterface::VERBOSITY_QUIET);
        $this->write('', OutputInterface::VERBOSITY_QUIET);
    }

    /** @param 'ok'|'fail'|'warn' $state */
    private function mark(string $state): string
    {
        return match ($state) {
            'ok' => $this->unicode() ? '<fg=green;options=bold>✔</>' : '<fg=green;options=bold>+</>',
            'fail' => $this->unicode() ? '<fg=red;options=bold>✖</>' : '<fg=red;options=bold>x</>',
            'warn' => '<fg=yellow;options=bold>!</>',
        };
    }

    private function flushWarnings(): void
    {
        $pending = $this->pending;
        $this->pending = [];

        foreach ($pending as [$message, $verboseOnly]) {
            $this->writeWarning($message, $verboseOnly);
        }
    }

    private function writeWarning(string $message, bool $verboseOnly): void
    {
        $this->write('  ' . $this->mark('warn') . ' ' . OutputFormatter::escape($message), $verboseOnly ? OutputInterface::VERBOSITY_VERBOSE : OutputInterface::VERBOSITY_NORMAL);
    }

    private function finishProgress(bool $clearOnly = false): void
    {
        if ($this->bar !== null) {
            if (!$clearOnly) {
                $this->bar->finish();
            }

            $this->bar->clear();
            $this->bar = null;
        }

        $this->clearIndicator();
        $this->phaseTitle = null;
    }

    private function clearIndicator(): void
    {
        if ($this->indicator !== null) {
            $this->progressOutput()->write("\r\x1b[2K");
            $this->indicator = null;
        }
    }

    private function canAnimate(): bool
    {
        return $this->output->isDecorated() && !$this->output->isQuiet();
    }

    private function progressOutput(): OutputInterface
    {
        return $this->output instanceof ConsoleOutputInterface ? $this->output->getErrorOutput() : $this->output;
    }

    private function write(string $text, int $verbosity = OutputInterface::VERBOSITY_NORMAL): void
    {
        $this->output->writeln($text, $verbosity);
    }

    private function unicode(): bool
    {
        return $this->output->isDecorated();
    }
}
