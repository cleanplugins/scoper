<?php

declare(strict_types=1);

namespace CleanPlugins\Scoper\Build;

use CleanPlugins\Scoper\Autoload\Generator;
use CleanPlugins\Scoper\Config\Config;
use CleanPlugins\Scoper\Console\Phase;
use CleanPlugins\Scoper\Console\Reporter;
use CleanPlugins\Scoper\Exception\ScoperException;
use CleanPlugins\Scoper\Prune\Pruner;
use CleanPlugins\Scoper\Scope\Scoper;
use CleanPlugins\Scoper\Support\Filesystem;
use CleanPlugins\Scoper\Support\Format;
use CleanPlugins\Scoper\Support\Path;
use CleanPlugins\Scoper\Support\ProcessRunner;
use CleanPlugins\Scoper\Verify\DevLeakScanner;
use CleanPlugins\Scoper\Verify\Identity;
use CleanPlugins\Scoper\Verify\LeakScanner;
use CleanPlugins\Scoper\Verify\Snapshot;
use CleanPlugins\Scoper\Verify\TreeVerifier;
use CleanPlugins\Scoper\Verify\Verifier;
use Throwable;
use ZipArchive;

/**
 * The pipeline:
 *
 *   1. prerequisites (release guards, version)
 *   2. clean the output tree
 *   3. assets, then production dependencies
 *   4. stage the source files, then snapshot them
 *   5. apply removals, verified against the snapshot
 *   6. scope namespaces and global symbols, verified against the snapshot
 *   7. generate the classmap autoloader
 *   8. verify (dev-only references, leaks, checks, probes)
 *   9. hash the tree, and optionally package it
 */
final class Builder
{
    private bool $snapshotCaptured = false;

    public function __construct(private readonly Config $config, private readonly Reporter $reporter, private readonly Filesystem $files, private readonly bool $noAssets = false, private readonly bool $stream = false) {}

    public function build(bool $package): BuildResult
    {
        $this->snapshotCaptured = false;
        $this->reporter->intro($package ? 'scoper · release' : 'scoper · build');
        $runner = new ProcessRunner($this->reporter->output(), $this->config->logPath(), $this->stream);
        $version = null;

        $phase = $this->reporter->phase('Prerequisites');
        $detail = 'prefix ' . $this->config->prefix->namespace . '\\';

        if ($package) {
            if (!class_exists(ZipArchive::class)) {
                throw new ScoperException('ext-zip is required to package a release');
            }

            $this->assertClean($runner);
            $version = (new Version($this->config))->read();
            $detail .= ' · ' . ($version ?? 'no version');
        }

        $phase->end($detail);

        $phase = $this->reporter->phase('Clean output');
        $this->removeWithRetry($this->config->outputPath());
        $this->files->mkdir($this->config->output);
        $this->files->mkdir($this->config->artifacts);
        $leftovers = array_diff(scandir($this->config->outputPath()) ?: [], ['.', '..']);

        if ($leftovers !== []) {
            throw new ScoperException('Could not clean ' . $this->config->output . ': ' . implode(', ', $leftovers));
        }

        $phase->end($this->config->output . '/ is empty');

        $hasAssets = $this->config->assetsCommand !== null || is_dir($this->config->distPath());

        if ($hasAssets) {
            $phase = $this->reporter->phase('Assets');
            $skipped = $this->noAssets && $this->config->assetsCommand !== null;
            $this->buildAssets($runner, $phase);
            $this->checkAssets();
            $assetCount = count($this->files->files($this->config->distPath()));
            $assetSize = $this->files->size($this->config->distPath());
            $phase->end(($skipped ? 'skipped · ' : '') . Format::count($assetCount) . ' assets · ' . Format::bytes($assetSize));
        }

        $phase = $this->reporter->phase('Dependencies');
        $packageCount = $this->installDependencies($runner, $phase);
        $phase->end(Format::count($packageCount) . ' packages');

        $phase = $this->reporter->phase('Stage source files');
        $phase->total(count($this->config->source));

        foreach ($this->config->source as $entry) {
            $phase->advance();
            $source = $this->config->absolute($entry);

            if (!file_exists($source)) {
                throw new ScoperException("Source entry not found: {$entry} (did the asset build produce it?)");
            }

            $this->files->copy($source, $this->config->outputPath() . '/' . $entry);
        }

        $phase->end(count($this->config->source) . ' entries');

        $phase = $this->reporter->phase('Snapshot');
        (new Snapshot($this->config, $this->files))->capture(fn() => $phase->advance());
        $this->snapshotCaptured = true;
        $snapshotFiles = $this->files->files($this->config->snapshotPath());
        $phase->end(Format::count(count($snapshotFiles)) . ' files · ' . Format::bytes($this->files->size($this->config->snapshotPath())));

        $phase = $this->reporter->phase('Removals');
        $pruned = (new Pruner($this->config, $this->files))->run();
        $tree = new TreeVerifier($this->config, $this->files);
        $tree->afterRemovals($pruned->deleted, $pruned->rewritten, fn() => $phase->advance());

        foreach ($pruned->warnings as $warning) {
            $this->reporter->warn($warning);
        }

        foreach ($pruned->unused as $warning) {
            $this->reporter->warn($warning, true);
        }

        $phase->end($pruned->files > 0 ? sprintf('%s files · %s patterns%s', Format::count($pruned->files), Format::count($pruned->patterns), $pruned->excluded > 0 ? ' · ' . Format::count($pruned->excluded) . ' kept' : '') : Format::count($pruned->patterns) . ' path patterns');
        $this->reporter->render($pruned->sections);

        $phase = $this->reporter->phase('Scope namespaces');
        $scope = (new Scoper($this->config, $this->files))->run($phase);

        foreach ($scope->warnings as $warning) {
            $this->reporter->warn($warning);
        }

        foreach ($scope->unused as $warning) {
            $this->reporter->warn($warning, true);
        }

        $phase->end(sprintf('%s namespaces · %s globals · %s edits', Format::count($scope->namespaces), Format::count($scope->globals), Format::count($scope->edits)));
        $this->reporter->render($scope->sections);

        $phase = $this->reporter->phase('Generate autoloader');
        $autoload = (new Generator($this->config, $this->files))->run($phase);

        foreach ($autoload->warnings as $warning) {
            $this->reporter->warn($warning);
        }

        $phase->end(Format::count($autoload->classes) . ' classes');
        $this->reporter->render($autoload->sections);

        $phase = $this->reporter->phase('Verify tree');
        $tick = fn() => $phase->advance();
        $intact = $tree->afterScope($scope->map['edits'] ?? [], $tick);
        $dev = (new DevLeakScanner($this->config, $this->files))->run($tick);

        if ($dev->leaks !== []) {
            throw new ScoperException(sprintf('%d dev-only dependency reference(s) in the shipped tree', count($dev->leaks)), array_slice($dev->leaks, 0, 40));
        }

        $identity = new Identity($this->config);
        $identity->assertIgnoredPackages($scope->map);
        $scan = (new LeakScanner($this->config, $this->files))->run($tick);

        if ($scan->leaks !== []) {
            throw new ScoperException(sprintf('%d leak(s) found in %d files', count($scan->leaks), $scan->files), array_slice($scan->leaks, 0, 40));
        }

        $verified = (new Verifier($this->config, $this->files))->run($tick);
        $sections = [['type' => 'text', 'text' => sprintf('No leaked symbols in %d files', $scan->files)]];

        $sections[] = ['type' => 'text', 'text' => $dev->packages > 0 ? sprintf('No dev-only dependency references in %d files', $dev->files) : 'No dev-only packages installed'];

        foreach ($verified->lines as $line) {
            $sections[] = ['type' => 'text', 'text' => $line];
        }

        $phase->end(sprintf('%s files intact · %s checks', Format::count($intact), Format::count($verified->checks + ($dev->packages > 0 ? 1 : 0))));
        $this->reporter->render($sections);

        $phase = $this->reporter->phase('Finalise');
        $this->files->remove($this->config->outputPath() . '/composer.json');
        $this->files->remove($this->config->outputPath() . '/composer.lock');

        // VCS metadata is never part of a production tree.
        foreach ($this->files->files($this->config->outputPath()) as $file) {
            if (basename($file) === '.gitignore') {
                $this->files->remove($file);
            }
        }

        $phase->end(count(array_diff(scandir($this->config->outputPath()) ?: [], ['.', '..'])) . ' root entries');

        $hash = $this->files->hash($this->config->outputPath());
        $files = $this->files->files($this->config->outputPath());
        $size = $this->files->size($this->config->outputPath());
        $release = null;

        if ($package) {
            $phase = $this->reporter->phase('Package release');
            $release = (new Packager($this->config, $this->files))->package($version, $phase);
            $phase->end(sprintf('%s files · %s', Format::count($release['entries']), Format::bytes($release['size'])));
        }

        $rows = [['tree sha256', $hash], ['artifact', sprintf('%s files · %s', Format::count(count($files)), Format::bytes($size))], ['map', Path::relative($this->config->projectDir, $this->config->mapPath())], ['snapshot', Path::relative($this->config->projectDir, $this->config->snapshotPath())]];

        if ($release !== null) {
            $rows[] = ['release', Path::relative($this->config->projectDir, $release['path']) . ' · ' . Format::bytes($release['size'])];
        } elseif ($this->isClean($runner)) {
            $rows[] = ['next', 'composer scoper:build --release'];
        }

        $this->reporter->summary($rows, $package ? 'Release ready' : 'Build complete');

        return new BuildResult($hash, count($files), $size, Path::relative($this->config->projectDir, $this->config->mapPath()), $release['path'] ?? null, $release['entries'] ?? 0, $release['size'] ?? 0);
    }

    /**
     * The snapshot this run captured. A snapshot left by an earlier build is never reported: it does
     * not describe the tree that failed.
     */
    public function capturedSnapshot(): ?string
    {
        $path = $this->config->snapshotPath();

        return $this->snapshotCaptured && is_dir($path) ? $path : null;
    }

    private function installDependencies(ProcessRunner $runner, Phase $phase): int
    {
        // Composer installs exactly what the lock pins; the manifest and lock are temporary inputs and
        // never part of the shipped tree.
        $this->writeInstallManifest();

        if (is_file($this->config->projectDir . '/composer.lock')) {
            $this->files->copy($this->config->projectDir . '/composer.lock', $this->config->outputPath() . '/composer.lock');
        }

        $args = ['install', '--no-dev', '--no-plugins', '--no-scripts', '--no-autoloader', '--no-interaction', ...$this->config->composerArgs];
        $command = $runner->composer() . ' ' . implode(' ', array_map('escapeshellarg', $args));

        if ($runner->run('composer', $command, $this->config->outputPath(), [], fn() => $phase->advance()) !== 0) {
            throw new ScoperException('composer install failed', [$runner->tail('composer')]);
        }

        $installed = $this->config->outputPath() . '/' . $this->config->vendor . '/composer/installed.json';

        if (!is_file($installed)) {
            throw new ScoperException("Composer did not write {$installed}");
        }

        $this->files->copy($installed, $this->config->installedPath());
        $data = json_decode((string) file_get_contents($installed), true) ?: [];
        $packageCount = count($data['packages'] ?? []);

        $this->writeProductionManifest();

        $this->dropStructural($this->config->outputPath() . '/' . $this->config->vendor . '/composer');
        $this->dropStructural($this->config->outputPath() . '/' . $this->config->vendor . '/autoload.php');

        $bin = (string) ($this->config->composerJson['config']['bin-dir'] ?? $this->config->vendor . '/bin');
        $this->dropStructural($this->config->outputPath() . '/' . trim($bin, '/'));

        return $packageCount;
    }

    private function buildAssets(ProcessRunner $runner, Phase $phase): void
    {
        if ($this->noAssets || $this->config->assetsCommand === null) {
            return;
        }

        if ($runner->run('assets', implode(' && ', $this->config->assetsCommand), $this->config->projectDir, [], fn() => $phase->advance()) !== 0) {
            throw new ScoperException('asset build failed', [$runner->tail('assets')]);
        }
    }

    private function checkAssets(): void
    {
        if ($this->config->assetsCheck === 'off' || $this->config->assetsSources === []) {
            return;
        }

        $sources = [];

        foreach ($this->config->assetsSources as $source) {
            $path = $this->config->absolute($source);

            if (file_exists($path)) {
                $sources = array_merge($sources, $this->files->files($path));
            }
        }

        if ($sources === []) {
            return;
        }

        $output = $this->files->files($this->config->distPath());
        $newestSource = max(array_map(static fn(string $file): int => (int) filemtime($file), $sources));
        $message = null;

        if ($output === []) {
            $message = "assets have not been built: {$this->config->dist} is empty";
        } else {
            $newestOutput = max(array_map(static fn(string $file): int => (int) filemtime($file), $output));

            if ($newestSource > $newestOutput) {
                $message = "assets are older than their sources ({$this->config->dist})";
            }
        }

        if ($message === null) {
            return;
        }

        if ($this->config->assetsCheck === 'fail') {
            throw new ScoperException($message);
        }

        $this->reporter->warn($message);
    }

    private function dropStructural(string $path): void
    {
        $this->removeWithRetry($path);
    }

    /**
     * Stage the manifest for `composer install`. Relative path repositories are rewritten to absolute
     * paths: the build tree is one directory deeper, so a path such as `../package` would otherwise
     * resolve relative to the build tree instead of the project root.
     */
    private function writeInstallManifest(): void
    {
        $manifest = $this->config->composerJson;

        foreach ((array) ($manifest['repositories'] ?? []) as $index => $repository) {
            if (($repository['type'] ?? null) !== 'path' || !is_string($repository['url'] ?? null)) {
                continue;
            }

            $url = $repository['url'];

            if (!Path::isAbsolute($url)) {
                $manifest['repositories'][$index]['url'] = Path::join($this->config->projectDir, $url);
            }
        }

        $this->files->write($this->config->outputPath() . '/composer.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }

    /**
     * Replace the copied manifest with a production-only one and drop the lock. The manifest exists
     * only while dependencies are installed; neither file is part of the shipped tree.
     */
    private function writeProductionManifest(): void
    {
        $manifest = $this->config->composerJson;

        foreach (['require-dev', 'autoload-dev', 'scripts', 'scripts-descriptions', 'extra'] as $key) {
            unset($manifest[$key]);
        }

        unset($manifest['config']['allow-plugins']);
        unset($manifest['require']['cleanplugins/scoper']);

        if (($manifest['config'] ?? []) === []) {
            unset($manifest['config']);
        }

        $this->files->write($this->config->outputPath() . '/composer.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        $this->files->remove($this->config->outputPath() . '/composer.lock');
    }

    private function removeWithRetry(string $path): void
    {
        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            try {
                $this->files->remove($path);

                return;
            } catch (Throwable $exception) {
                if ($attempt === 5) {
                    throw new ScoperException("Could not remove {$path}: {$exception->getMessage()}");
                }

                usleep(200000);
            }
        }
    }

    private function assertClean(ProcessRunner $runner): void
    {
        if (!is_dir($this->config->projectDir . '/.git')) {
            return;
        }

        if ($runner->run('git', 'git status --porcelain', $this->config->projectDir) !== 0) {
            throw new ScoperException('Could not read the git working tree status');
        }

        $dirty = trim($runner->output('git'));

        if ($dirty === '') {
            return;
        }

        $allowed = Format::enabled(getenv('SCOPER_ALLOW_DIRTY'));

        if (!$allowed) {
            throw new ScoperException('The working tree has uncommitted changes; a release must be traceable to a commit.', ['Commit first, or set SCOPER_ALLOW_DIRTY=1 for a local snapshot.']);
        }

        $this->reporter->warn('packaging uncommitted changes (SCOPER_ALLOW_DIRTY=1)');
    }

    private function isClean(ProcessRunner $runner): bool
    {
        if (!is_dir($this->config->projectDir . '/.git')) {
            return true;
        }

        if ($runner->run('git', 'git status --porcelain', $this->config->projectDir) !== 0) {
            return false;
        }

        return trim($runner->output('git')) === '';
    }
}
