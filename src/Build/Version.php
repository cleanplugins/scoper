<?php

declare(strict_types=1);

namespace CleanPlugins\Scoper\Build;

use CleanPlugins\Scoper\Config\Config;
use CleanPlugins\Scoper\Exception\ScoperException;

final class Version
{
    private const HEADER_BYTES = 8192;

    public function __construct(private readonly Config $config) {}

    public function read(): ?string
    {
        $file = $this->config->pluginFile;

        if ($file === null) {
            $version = $this->config->composerJson['version'] ?? null;

            return is_string($version) && $version !== '' ? $version : null;
        }

        $path = $this->config->absolute($file);

        if (!is_file($path)) {
            throw new ScoperException("Version file not found: {$path}");
        }

        $data = @file_get_contents($path, false, null, 0, self::HEADER_BYTES);

        if ($data === false) {
            throw new ScoperException("Could not read {$path}");
        }

        // WordPress reads only the first 8 KB and normalizes CR-only line endings.
        $data = str_replace("\r", "\n", $data);

        foreach (preg_split("/\n/", $data) ?: [] as $line) {
            if (preg_match('/^(?:[ \t]*<\?(?:php)?)?[ \t\/*#@]*Version:(.*)$/i', $line, $matches) !== 1) {
                continue;
            }

            $version = self::clean($matches[1]);

            if ($version !== '') {
                return $version;
            }
        }

        throw new ScoperException("Could not read the Version header from {$file}");
    }

    /** WordPress's _cleanup_header_comment() behavior. */
    private static function clean(string $value): string
    {
        return trim((string) preg_replace('/\s*(?:\*\/|\?>).*/', '', $value));
    }
}
