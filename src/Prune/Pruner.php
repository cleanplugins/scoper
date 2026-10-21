<?php

declare(strict_types=1);

namespace CleanPlugins\Scoper\Prune;

use CleanPlugins\Scoper\Config\Config;
use CleanPlugins\Scoper\Config\Glob;
use CleanPlugins\Scoper\Exception\ScoperException;
use CleanPlugins\Scoper\Support\Filesystem;
use CleanPlugins\Scoper\Support\InstalledRepository;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/**
 * Applies `remove` to a staged build tree, before anything is scoped.
 *
 * Removals describe source text, never scoped output. `package` drops a package entirely, `path`
 * deletes files (globs supported), and `string` empties an exact literal while keeping the code
 * valid; it never deletes a statement.
 */
final class Pruner
{
    public function __construct(private readonly Config $config, private readonly Filesystem $files) {}

    public function run(): PruneResult
    {
        $buildDir = $this->config->outputPath();
        $vendor = $this->config->vendor;
        $dist = $this->config->dist;
        $policy = $this->config->policy;

        $installedPath = is_file($this->config->installedPath()) ? $this->config->installedPath() : $buildDir . '/' . $vendor . '/composer/installed.json';
        $repository = InstalledRepository::fromFile($installedPath, $vendor);
        $packages = $repository->packages();
        $packageRows = [];
        $pathRows = [];
        $stringRows = [];
        $warnings = [];
        $unused = [];
        $deleted = [];
        $rewritten = [];
        $prunedFiles = 0;
        $excludedFiles = 0;

        // 1. Packages, expanded first: a value may wildcard the package name (symfony/polyfill-*).
        $removedNames = [];
        $packagePatterns = 0;

        foreach (array_keys($policy->removedPackages) as $pattern) {
            $matches = $repository->names() === [] ? [] : $policy->packagesMatching($repository->names(), $pattern);

            if ($matches === []) {
                $unused[] = "package \"{$pattern}\" matched no installed package";

                continue;
            }

            ++$packagePatterns;

            foreach ($matches as $package) {
                if (isset($removedNames[$package])) {
                    continue;
                }

                $directory = $repository->directoryFor($package);

                if (!is_dir($buildDir . '/' . $directory)) {
                    $unused[] = "{$package} is not installed; drop the entry";

                    continue;
                }

                $removed = $this->files->relativeFiles($buildDir . '/' . $directory, $buildDir);

                try {
                    $this->files->remove($buildDir . '/' . $directory);
                } catch (\Throwable $exception) {
                    throw new ScoperException("Could not remove {$directory}: {$exception->getMessage()}");
                }

                $deleted = array_merge($deleted, $removed);
                $removedNames[$package] = true;
                $count = count($removed);
                $packageRows[] = [$package, sprintf('%d files', $count)];
            }
        }

        // 2. A removed package that another shipped package still requires is a broken tree at runtime.
        foreach ($packages as $name => $package) {
            foreach ((array) ($package['require'] ?? []) as $dependency => $version) {
                if (isset($removedNames[$dependency]) && !isset($removedNames[$name]) && is_dir($buildDir . '/' . $repository->directoryFor($name))) {
                    $warnings[] = "{$name} requires {$dependency}, which is removed";
                }
            }
        }

        // 3. Paths (globs supported), limited to the vendor and dist trees. `exclude` keeps files by
        //    name, so "every .md except the licences" is one entry.
        $candidates = array_merge($this->files->relativeFiles($buildDir . '/' . $vendor, $buildDir), $this->files->relativeFiles($buildDir . '/' . $dist, $buildDir));

        foreach (array_keys($policy->removedPaths) as $pattern) {
            $matched = [];
            $excluded = [];
            $used = [];
            $excludes = $policy->removedExcludes[$pattern] ?? [];

            foreach ($candidates as $relative) {
                if (!is_file($buildDir . '/' . $relative) || !Glob::isIgnored($relative, [$pattern])) {
                    continue;
                }

                $hit = null;

                foreach ($excludes as $exclude) {
                    if (Glob::matchesName(basename($relative), $exclude)) {
                        $hit = $exclude;

                        break;
                    }
                }

                if ($hit !== null) {
                    $excluded[] = $relative;
                    $used[$hit] = true;

                    continue;
                }

                $matched[] = $relative;
            }

            if ($matched === [] && $excluded === []) {
                $unused[] = "path \"{$pattern}\" matched nothing";

                continue;
            }

            foreach ($excludes as $exclude) {
                if (!isset($used[$exclude])) {
                    $unused[] = "exclude \"{$exclude}\" matched nothing for {$pattern}";
                }
            }

            foreach ($matched as $relative) {
                try {
                    $this->files->remove($buildDir . '/' . $relative);
                } catch (\Throwable $exception) {
                    throw new ScoperException("Could not remove {$relative}: {$exception->getMessage()}");
                }
            }

            $deleted = array_merge($deleted, $matched);

            $pathRows[] = [$pattern, sprintf('%d files%s', count($matched), $excluded === [] ? '' : sprintf(' (%d excluded)', count($excluded)))];
            $prunedFiles += count($matched);
            $excludedFiles += count($excluded);
        }

        foreach ([$vendor, $dist] as $root) {
            if (is_dir($buildDir . '/' . $root)) {
                $this->pruneEmpty($buildDir . '/' . $root, $buildDir . '/' . $root);
            }
        }

        // 4. String literals: empty them, never delete the statement.
        if ($policy->removedStrings !== []) {
            $parser = (new ParserFactory())->createForNewestSupportedVersion();
            $finder = new NodeFinder();

            // Effective rules per package: a package-specific rule wins over a package-less one.
            $rulesByPackage = [];

            foreach ($repository->names() as $name) {
                $rulesByPackage[$name] = $policy->removedStrings[$name] ?? [];
            }

            foreach ($policy->removedStrings['*'] ?? [] as $literal => $reason) {
                foreach (array_keys($rulesByPackage) as $name) {
                    if (isset($rulesByPackage[$name][$literal])) {
                        continue;
                    }

                    $rulesByPackage[$name][$literal] = $reason;
                }
            }

            $matched = [];

            foreach ($rulesByPackage as $name => $rules) {
                if ($rules === []) {
                    continue;
                }

                $directory = $repository->directoryFor($name);

                if (!is_dir($buildDir . '/' . $directory)) {
                    continue;
                }

                foreach ($this->files->relativeFiles($buildDir . '/' . $directory, $buildDir) as $relative) {
                    if (!str_ends_with($relative, '.php')) {
                        continue;
                    }

                    $code = $this->files->read($buildDir . '/' . $relative);
                    $ast = $parser->parse($code);

                    if ($ast === null) {
                        continue;
                    }

                    /** @var list<String_> $literals */
                    $literals = $finder->findInstanceOf($ast, String_::class);
                    $edits = [];
                    $counts = [];

                    foreach ($literals as $literal) {
                        foreach ($rules as $value => $reason) {
                            if ($literal->value !== $value) {
                                continue;
                            }

                            $edits[] = [$literal->getStartFilePos(), $literal->getEndFilePos()];
                            $counts[$value] = ($counts[$value] ?? 0) + 1;
                        }
                    }

                    if ($edits === []) {
                        continue;
                    }

                    usort($edits, static fn(array $a, array $b): int => $b[0] <=> $a[0]);

                    foreach ($edits as [$start, $end]) {
                        $code = substr_replace($code, "''", $start, $end - $start + 1);
                    }

                    try {
                        token_get_all($code, TOKEN_PARSE);
                    } catch (\ParseError $error) {
                        throw new ScoperException("{$relative} does not parse after removing literals: {$error->getMessage()}");
                    }

                    $this->files->write($buildDir . '/' . $relative, $code);
                    $rewritten[$relative] = hash('sha256', $code);

                    foreach ($counts as $value => $count) {
                        $matched[$value] = ($matched[$value] ?? 0) + $count;
                    }
                }
            }

            foreach ($policy->removedStrings as $scope => $rules) {
                foreach ($rules as $value => $reason) {
                    $count = $matched[$value] ?? 0;

                    if ($count === 0) {
                        $unused[] = "string \"{$value}\" matched nothing in " . ($scope === '*' ? 'any package' : $scope);

                        continue;
                    }

                    if ($scope === '*') {
                        $packagesHit = array_keys(array_filter($rulesByPackage, static fn(array $packageRules): bool => isset($packageRules[$value])));

                        if (count($packagesHit) > 1) {
                            $warnings[] = sprintf('package-less string "%s" is configured for %d package(s); consider scoping it', $value, count($packagesHit));
                        }
                    }

                    $stringRows[] = [$value, sprintf('%d occurrence(s) in %s', $count, $scope === '*' ? 'all packages' : $scope)];
                }
            }
        }

        return new PruneResult([['type' => 'table', 'title' => 'Packages', 'rows' => $packageRows], ['type' => 'table', 'title' => 'Paths', 'rows' => $pathRows], ['type' => 'table', 'title' => 'Strings', 'rows' => $stringRows]], $warnings, $unused, array_values(array_unique($deleted)), $rewritten, count($removedNames), $packagePatterns, count($pathRows), count($stringRows), $prunedFiles, $excludedFiles);
    }

    private function pruneEmpty(string $directory, string $root): void
    {
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . '/' . $entry;

            if (is_dir($path) && !is_link($path)) {
                $this->pruneEmpty($path, $root);
            }
        }

        if ($directory !== $root && array_diff(scandir($directory) ?: [], ['.', '..']) === []) {
            @rmdir($directory);
        }
    }
}
