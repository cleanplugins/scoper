<?php

declare(strict_types=1);

namespace CleanPlugins\Scoper\Support;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

/**
 * Runs child processes, always capturing their output to the build log and optionally streaming it.
 */
final class ProcessRunner
{
    /** @var array<string, Process> */
    private array $processes = [];

    /** @var array<string, string> */
    private array $buffers = [];

    /** @var array<string, string> */
    private array $commands = [];

    public function __construct(private readonly OutputInterface $output, private readonly string $logPath, private readonly bool $stream = false) {}

    public function start(string $id, string $command, string $cwd, array $env = []): void
    {
        $this->commands[$id] = $command;
        $this->buffers[$id] = '';
        $this->writeLog("\n$ {$command}\n");

        $process = Process::fromShellCommandline($command, $cwd, $env, null, null);
        $process->setTimeout(null);
        $process->start(function (string $type, string $buffer) use ($id): void {
            $this->buffers[$id] .= $buffer;
            $this->writeLog($buffer);

            if ($this->stream) {
                $this->output->write($buffer);
            }
        });

        $this->processes[$id] = $process;
    }

    public function wait(string $id, ?callable $tick = null): int
    {
        if (!isset($this->processes[$id])) {
            throw new \RuntimeException("Process {$id} was not started");
        }

        $process = $this->processes[$id];

        while ($process->isRunning()) {
            if ($tick !== null) {
                $tick();
            }

            usleep(100000);
        }

        $process->wait();

        return $process->getExitCode() ?? 1;
    }

    public function run(string $id, string $command, string $cwd, array $env = [], ?callable $tick = null): int
    {
        $this->start($id, $command, $cwd, $env);

        return $this->wait($id, $tick);
    }

    public function output(string $id): string
    {
        return $this->buffers[$id] ?? '';
    }

    public function command(string $id): string
    {
        return $this->commands[$id] ?? '';
    }

    public function tail(string $id, int $lines = 30): string
    {
        $output = trim($this->output($id));

        if ($output === '') {
            return '';
        }

        return implode("\n", array_slice(preg_split('/\r?\n/', $output) ?: [], -$lines));
    }

    /** The composer executable for this process, as a shell fragment. */
    public function composer(): string
    {
        $binary = getenv('COMPOSER_BINARY');

        if (!is_string($binary) || $binary === '') {
            return self::quote('composer');
        }

        $binary = str_replace('\\', '/', $binary);

        if (str_ends_with($binary, '.phar')) {
            return self::quote(PHP_BINARY) . ' ' . self::quote($binary);
        }

        return self::quote($binary);
    }

    private static function quote(string $value): string
    {
        return escapeshellarg($value);
    }

    private function writeLog(string $text): void
    {
        $directory = dirname($this->logPath);

        if (!is_dir($directory)) {
            @mkdir($directory, 0777, true);
        }

        @file_put_contents($this->logPath, $text, FILE_APPEND);
    }
}
