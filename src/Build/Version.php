<?php

declare(strict_types=1);

namespace CleanPlugins\Scoper\Build;

use CleanPlugins\Scoper\Config\Config;
use CleanPlugins\Scoper\Exception\ScoperException;

final class Version
{
    public function __construct(private readonly Config $config) {}

    public function read(): ?string
    {
        $settings = $this->config->releaseVersion;

        if ($settings === null) {
            $version = $this->config->composerJson['version'] ?? null;

            return is_string($version) && $version !== '' ? $version : null;
        }

        $path = $this->config->absolute($settings['file']);

        if (!is_file($path)) {
            throw new ScoperException("Version file not found: {$path}");
        }

        $contents = (string) file_get_contents($path);
        $constant = null;
        $header = null;

        if ($settings['constant'] !== null) {
            if (preg_match("/define\\(\\s*'" . preg_quote($settings['constant'], '/') . "'\\s*,\\s*'([^']+)'\\s*\\)/", $contents, $matches) !== 1) {
                throw new ScoperException("Could not read {$settings['constant']} from {$settings['file']}");
            }

            $constant = $matches[1];
        }

        if ($settings['header'] !== null) {
            if (preg_match('/^ \* ' . preg_quote($settings['header'], '/') . ':\s*(\S+)\s*$/m', $contents, $matches) !== 1) {
                throw new ScoperException("Could not read the {$settings['header']} header from {$settings['file']}");
            }

            $header = $matches[1];
        }

        if ($constant !== null && $header !== null && $constant !== $header) {
            throw new ScoperException("{$settings['constant']} ({$constant}) and the {$settings['header']} header ({$header}) disagree");
        }

        return $constant ?? $header;
    }
}
