<?php

declare(strict_types=1);

namespace CleanPlugins\Scoper;

use CleanPlugins\Scoper\Console\BuildCommand;
use Composer\InstalledVersions;
use Symfony\Component\Console\Application as SymfonyApplication;

final class Application extends SymfonyApplication
{
    public function __construct()
    {
        $version = class_exists(InstalledVersions::class) ? InstalledVersions::getPrettyVersion('cleanplugins/scoper') : null;

        parent::__construct('scoper', $version === null || str_contains($version, '+no-version-set') ? '0.1.0' : $version);

        $this->add(new BuildCommand());
    }
}
