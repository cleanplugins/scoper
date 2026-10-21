<?php

declare(strict_types=1);

namespace CleanPlugins\Scoper\Config;

use CleanPlugins\Scoper\Exception\ScoperException;
use CleanPlugins\Scoper\Support\Path;

/**
 * Resolved build configuration for one project.
 *
 * Every setting lives under `extra.scoper`. Missing configuration, top-level build keys and the
 * retired `extra.build` compatibility key are all rejected.
 */
final class Config
{
    /**
     * @param list<string> $source
     * @param array<string, string> $probes
     */
    private function __construct(public readonly string $projectDir, public readonly string $configPath, public readonly array $composerJson, public readonly Prefix $prefix, public readonly Policy $policy, public readonly string $vendor, public readonly string $dist, public readonly string $output, public readonly string $artifacts, public readonly string $releases, public readonly array $source, public readonly ?array $assetsCommand, public readonly array $assetsSources, public readonly string $assetsCheck, public readonly array $composerArgs, public readonly array $probes, public readonly string $releaseName, public readonly ?string $pluginFile) {}

    public static function load(string $projectDir, ?string $configFile = null): self
    {
        $projectDir = Path::normalize(realpath($projectDir) ?: $projectDir);
        $configPath = $configFile !== null ? Path::normalize($configFile) : $projectDir . '/composer.json';

        if (!is_file($configPath)) {
            throw new ScoperException("Configuration not found: {$configPath}");
        }

        $json = json_decode((string) file_get_contents($configPath), true);

        if (!is_array($json)) {
            throw new ScoperException("{$configPath} is not valid JSON");
        }

        $extra = is_array($json['extra'] ?? null) ? $json['extra'] : [];

        if (array_key_exists('build', $extra)) {
            throw new ScoperException("{$configPath} uses extra.build; move the settings under extra.scoper");
        }

        if (array_key_exists('build', $json) || array_key_exists('scoper', $json)) {
            throw new ScoperException("{$configPath} has top-level build/scoper settings; move them under extra.scoper");
        }

        $scoper = $extra['scoper'] ?? null;

        if (!is_array($scoper) || $scoper === []) {
            throw new ScoperException("No scoper configuration found under extra.scoper in {$configPath}");
        }

        $settings = $scoper;

        self::rejectUnknown($settings);

        $vendor = self::relativePath('vendor', $settings['vendor'] ?? ($json['config']['vendor-dir'] ?? 'vendor'));
        $dist = self::relativePath('dist', $settings['dist'] ?? 'dist');
        $output = self::relativePath('output', $settings['output'] ?? 'build');
        $artifacts = self::relativePath('artifacts', $settings['artifacts'] ?? '.build');
        $releases = self::relativePath('releases', $settings['releases'] ?? 'releases');

        $source = self::source($settings['source'] ?? null);

        self::assertNoOverlap($source, $output, $artifacts);

        $assets = is_array($settings['assets'] ?? null) ? $settings['assets'] : [];
        $assetsCommand = self::command($assets['command'] ?? null);
        $assetsSources = self::stringList('assets.sources', $assets['sources'] ?? []);
        $assetsCheck = (string) ($assets['check'] ?? 'warn');

        if (!in_array($assetsCheck, ['off', 'warn', 'fail'], true)) {
            throw new ScoperException('assets.check must be one of off, warn, fail');
        }

        $composer = is_array($settings['composer'] ?? null) ? $settings['composer'] : [];
        $composerArgs = self::stringList('composer.args', $composer['args'] ?? []);

        $probes = self::probes($settings['verify'] ?? null);

        $release = is_array($settings['release'] ?? null) ? $settings['release'] : [];

        if (array_key_exists('version', $release)) {
            throw new ScoperException('release.version is no longer supported; use release.plugin_file');
        }

        $releaseName = trim((string) ($release['name'] ?? self::defaultReleaseName((string) ($json['name'] ?? ''))));

        if ($releaseName === '') {
            throw new ScoperException('release.name must be a non-empty name');
        }

        $pluginFile = self::pluginFile($release['plugin_file'] ?? null);

        return new self($projectDir, $configPath, $json, Prefix::from($settings['prefix'] ?? null), Policy::from($settings, $vendor, $dist), $vendor, $dist, $output, $artifacts, $releases, $source, $assetsCommand, $assetsSources, $assetsCheck, $composerArgs, $probes, $releaseName, $pluginFile);
    }

    public function absolute(string $relative = ''): string
    {
        return $relative === '' ? $this->projectDir : Path::join($this->projectDir, $relative);
    }

    public function outputPath(): string
    {
        return $this->absolute($this->output);
    }

    public function vendorPath(): string
    {
        return $this->absolute($this->vendor);
    }

    public function distPath(): string
    {
        return $this->absolute($this->dist);
    }

    public function artifactsPath(): string
    {
        return $this->absolute($this->artifacts);
    }

    public function mapPath(): string
    {
        return $this->absolute($this->artifacts . '/prefix-map.json');
    }

    public function installedPath(): string
    {
        return $this->absolute($this->artifacts . '/installed.json');
    }

    public function snapshotPath(): string
    {
        return $this->absolute($this->artifacts . '/snapshot');
    }

    public function logPath(): string
    {
        return $this->absolute($this->artifacts . '/build.log');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'prefix' => $this->prefix->namespace,
            'global_prefixes' => $this->prefix->globals,
            'vendor' => $this->vendor,
            'dist' => $this->dist,
            'output' => $this->output,
            'artifacts' => $this->artifacts,
            'ignored_packages' => $this->policy->ignoredPackages,
            'ignored_paths' => $this->policy->ignoredPaths,
            'ignored_namespaces' => $this->policy->ignoredNamespaces,
            'ignored_globals' => $this->policy->ignoredGlobals,
            'ignored_strings' => $this->policy->ignoredStrings,
            'removed_packages' => $this->policy->removedPackages,
            'removed_paths' => $this->policy->removedPaths,
            'removed_excludes' => $this->policy->removedExcludes,
            'removed_strings' => $this->policy->removedStrings,
            'override_globals' => $this->policy->overrideGlobals,
            'override_namespaces' => $this->policy->overrideNamespaces,
        ];
    }

    private static function rejectUnknown(array $settings): void
    {
        $known = ['prefix', 'ignore', 'remove', 'override', 'source', 'vendor', 'dist', 'output', 'artifacts', 'releases', 'assets', 'composer', 'verify', 'release'];
        $unknown = array_diff(array_keys($settings), $known);

        if ($unknown !== []) {
            throw new ScoperException('unknown key(s): ' . implode(', ', $unknown));
        }
    }

    /** @param mixed $value @return list<string> */
    private static function source(mixed $value): array
    {
        $source = self::stringList('source', $value);

        if ($source === []) {
            throw new ScoperException('source must list the files and directories to ship');
        }

        $unique = [];

        foreach ($source as $entry) {
            $unique[] = self::relativePath('source', $entry);
        }

        return array_values(array_unique($unique));
    }

    /** @param mixed $value @return list<string>|null */
    private static function command(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            return [$value];
        }

        if (!is_array($value) || !array_is_list($value) || $value === []) {
            throw new ScoperException('assets.command must be a non-empty string or a list of strings');
        }

        $commands = [];

        foreach ($value as $command) {
            if (!is_string($command) || trim($command) === '') {
                throw new ScoperException('assets.command must contain non-empty strings');
            }

            $commands[] = $command;
        }

        return $commands;
    }

    /** @return array<string, string> */
    private static function probes(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new ScoperException('verify must map package names to probe files');
        }

        $probes = [];

        foreach ($value as $package => $file) {
            $package = (string) $package;

            if ($package === 'probes') {
                throw new ScoperException('verify.probes is retired; map package names directly to probe files');
            }

            if ($package === 'own') {
                throw new ScoperException('verify.own is retired; the whole staged tree is now checked against the build snapshot');
            }

            if (preg_match('#^[a-z0-9][a-z0-9_.-]*/[a-z0-9][a-z0-9_.-]*$#', $package) !== 1) {
                throw new ScoperException("verify key \"{$package}\" is not a package name (expected vendor/name)");
            }

            $probes[$package] = self::relativePath('verify.' . $package, $file);
        }

        return $probes;
    }

    private static function stringList(string $where, mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        $values = is_array($value) ? $value : [$value];

        if (!array_is_list($values)) {
            throw new ScoperException("{$where} must be a string or a list of strings");
        }

        $strings = [];

        foreach ($values as $entry) {
            if (!is_string($entry) || trim($entry) === '') {
                throw new ScoperException("{$where} must contain non-empty strings");
            }

            $strings[] = $entry;
        }

        return $strings;
    }

    private static function relativePath(string $where, mixed $value): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new ScoperException("{$where} must be a non-empty relative path");
        }

        $value = trim(str_replace('\\', '/', $value));
        $value = trim($value, '/');

        if (Path::isAbsolute($value) || preg_match('#^[A-Za-z]:#', $value) === 1) {
            throw new ScoperException("{$where} must be relative to the project, got {$value}");
        }

        if (in_array('..', explode('/', $value), true)) {
            throw new ScoperException("{$where} must not escape the project: {$value}");
        }

        return Path::normalize($value);
    }

    /**
     * @param list<string> $source
     */
    private static function assertNoOverlap(array $source, string $output, string $artifacts): void
    {
        if (Path::isInside($artifacts, $output)) {
            throw new ScoperException("artifacts ({$artifacts}) must not be inside output ({$output})");
        }

        foreach ($source as $entry) {
            if (Path::isInside($output, $entry) || Path::isInside($entry, $output)) {
                throw new ScoperException("source entry {$entry} overlaps output {$output}");
            }

            if (Path::isInside($artifacts, $entry) || Path::isInside($entry, $artifacts)) {
                throw new ScoperException("source entry {$entry} overlaps artifacts {$artifacts}");
            }
        }
    }

    private static function pluginFile(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value) || trim($value) === '') {
            throw new ScoperException('release.plugin_file must be a non-empty path');
        }

        return self::relativePath('release.plugin_file', $value);
    }

    private static function defaultReleaseName(string $package): string
    {
        $parts = explode('/', $package);

        return trim((string) end($parts));
    }
}
