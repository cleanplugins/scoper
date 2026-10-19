<?php

declare(strict_types=1);

namespace CleanPlugins\Scoper\Support;

/**
 * Composer's installed package list, with each package's build-relative directory.
 *
 * `install-path` is relative to `<vendor>/composer`; honouring it keeps packages installed outside
 * the default `vendor/name` layout (installers, custom paths) working.
 */
final class InstalledRepository
{
    /** @param array<string, array<string, mixed>> $packages */
    private function __construct(private readonly array $packages, private readonly string $vendor) {}

    public static function fromFile(string $path, string $vendor): self
    {
        $json = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $packages = [];

        foreach ((array) ($json['packages'] ?? []) as $package) {
            if (is_array($package) && isset($package['name'])) {
                $packages[(string) $package['name']] = $package;
            }
        }

        return new self($packages, trim($vendor, '/'));
    }

    /** @return array<string, array<string, mixed>> */
    public function packages(): array
    {
        return $this->packages;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->packages);
    }

    /** @param array<string, mixed> $package */
    public function directory(array $package): string
    {
        $install = $package['install-path'] ?? null;

        if (is_string($install) && $install !== '') {
            // Relative to `vendor/composer`, e.g. `../acme/library`.
            return Path::join($this->vendor . '/composer', $install);
        }

        return $this->vendor . '/' . $package['name'];
    }

    /** The build-relative directory for a package name, or the conventional one when it is unknown. */
    public function directoryFor(string $package): string
    {
        if (isset($this->packages[$package])) {
            return $this->directory($this->packages[$package]);
        }

        return $this->vendor . '/' . $package;
    }
}
