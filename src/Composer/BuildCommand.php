<?php

declare(strict_types=1);

namespace CleanPlugins\Scoper\Composer;

use CleanPlugins\Scoper\Console\BuildRunner;
use Composer\Command\BaseCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Composer's command adapter. Composer requires plugin commands to extend its own BaseCommand; the
 * actual build lives in the shared runner so the standalone binary uses the same code.
 */
final class BuildCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setName('scoper:build')->setDescription('Build the production package tree');
        BuildRunner::configure($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return (new BuildRunner())->run($input, $output);
    }
}
