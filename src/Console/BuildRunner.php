<?php

declare(strict_types=1);

namespace CleanPlugins\Scoper\Console;

use CleanPlugins\Scoper\Build\Builder;
use CleanPlugins\Scoper\Config\Config;
use CleanPlugins\Scoper\Support\Filesystem;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The build command body, shared by the standalone Symfony application and the Composer plugin's
 * `Composer\Command\BaseCommand`. Keeping the work here avoids duplicating the pipeline wiring.
 */
final class BuildRunner
{
    public static function configure(Command $command): void
    {
        $command->addOption('no-assets', null, InputOption::VALUE_NONE, 'Skip the asset command and use the existing dist directory');
        $command->addOption('stream', null, InputOption::VALUE_NONE, 'Stream composer and asset output to the terminal');
        $command->addOption('release', null, InputOption::VALUE_NONE, 'Also write a release zip');
    }

    public function run(InputInterface $input, OutputInterface $output, ?string $projectDir = null): int
    {
        $projectDir ??= (string) getcwd();
        $reporter = new Reporter($output);
        $config = null;
        $builder = null;

        try {
            $config = Config::load($projectDir);
            $builder = new Builder($config, $reporter, new Filesystem($config->projectDir), (bool) $input->getOption('no-assets'), (bool) $input->getOption('stream'));
            $builder->build((bool) $input->getOption('release'));

            return Command::SUCCESS;
        } catch (\Throwable $error) {
            $reporter->fatal($error, $config?->logPath(), $builder?->capturedSnapshot());

            return Command::FAILURE;
        }
    }
}
