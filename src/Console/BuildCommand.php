<?php

declare(strict_types=1);

namespace CleanPlugins\Scoper\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'scoper:build', description: 'Build the production package tree')]
final class BuildCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('working-dir', null, InputOption::VALUE_REQUIRED, 'Project directory (defaults to the current directory)');
        BuildRunner::configure($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return (new BuildRunner())->run($input, $output, $this->projectDir($input));
    }

    private function projectDir(InputInterface $input): string
    {
        $value = $input->getOption('working-dir');

        if (!is_string($value) || $value === '') {
            return (string) getcwd();
        }

        if (str_starts_with($value, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $value) === 1) {
            return realpath($value) ?: $value;
        }

        return realpath(getcwd() . '/' . $value) ?: getcwd() . '/' . $value;
    }
}
