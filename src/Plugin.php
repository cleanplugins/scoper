<?php

declare(strict_types=1);

namespace CleanPlugins\Scoper;

use CleanPlugins\Scoper\Composer\BuildCommand;
use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\Capability\CommandProvider;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;

/**
 * Registers `composer scoper:build` by loading the command from this package.
 *
 * A Composer plugin is the right mechanism for a command that lives in a dependency: class-name
 * scripts are created from the root package's own autoload map only, so a dependency's command
 * class is not visible to them.
 */
final class Plugin implements PluginInterface, Capable, CommandProvider
{
    public function activate(Composer $composer, IOInterface $io): void {}

    public function deactivate(Composer $composer, IOInterface $io): void {}

    public function uninstall(Composer $composer, IOInterface $io): void {}

    public function getCapabilities(): array
    {
        return [CommandProvider::class => self::class];
    }

    public function getCommands(): array
    {
        return [new BuildCommand()];
    }
}
