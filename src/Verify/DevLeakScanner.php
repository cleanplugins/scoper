<?php

declare(strict_types=1);

namespace CleanPlugins\Scoper\Verify;

use CleanPlugins\Scoper\Config\Config;
use CleanPlugins\Scoper\Exception\ScoperException;
use CleanPlugins\Scoper\Support\Filesystem;
use CleanPlugins\Scoper\Support\InstalledRepository;
use CleanPlugins\Scoper\Support\Path;
use PhpParser\Node;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use ReflectionClass;
use ReflectionFunction;
use Throwable;

/**
 * Fails the build when the shipped tree references a symbol provided only by a dev dependency.
 *
 * The build installs `--no-dev`, so such a reference resolves in the workspace but not in the
 * produced plugin. The index is built from the project's dev install and the production package
 * list; the shipped tree is scanned with name resolution, so imports, fully qualified names, global
 * function calls and class-name strings are all covered.
 */
final class DevLeakScanner extends NodeVisitorAbstract
{
    /** @var array<string, string> namespace prefix => package */
    private array $namespaces = [];

    /** @var array<string, string> PSR-0 class prefix => package */
    private array $classPrefixes = [];

    /** @var array<string, array{package: string, paths: list<string>}> PSR-4 prefix => package and roots */
    private array $psr4 = [];

    /** @var array<string, true> files already parsed into the symbol index */
    private array $indexedFiles = [];

    /** @var array<string, string> class FQN => package */
    private array $classes = [];

    /** @var array<string, string> function FQN => package */
    private array $functions = [];

    /** @var array<string, true> */
    private array $declaredClasses = [];

    /** @var array<string, true> */
    private array $declaredFunctions = [];

    /** @var list<array{file: string, line: int, name: string, package: string}> */
    private array $candidates = [];

    private string $file = '';

    private readonly Parser $parser;

    public function __construct(private readonly Config $config, private readonly Filesystem $files)
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
    }

    public function run(?callable $tick = null): DevLeakResult
    {
        $packages = $this->buildIndex($tick);

        if ($packages === 0) {
            return new DevLeakResult([], 0, 0, 0);
        }

        $phpFiles = [];
        $ownFiles = 0;

        foreach ($this->files->relativeFiles($this->config->outputPath(), $this->config->outputPath()) as $relative) {
            if (!str_ends_with($relative, '.php')) {
                continue;
            }

            $phpFiles[] = $relative;

            if (!str_starts_with($relative, $this->config->vendor . '/')) {
                ++$ownFiles;
            }
        }

        foreach ($phpFiles as $relative) {
            if ($tick !== null) {
                $tick();
            }

            $this->file = $relative;
            $this->scanFile($relative);
        }

        $leaks = [];

        foreach ($this->candidates as $candidate) {
            if (isset($this->declaredClasses[$candidate['name']]) || isset($this->declaredFunctions[$candidate['name']])) {
                continue;
            }

            $leaks[] = sprintf('%s:%d uses %s from dev-only package %s', $candidate['file'], $candidate['line'], $candidate['name'], $candidate['package']);
        }

        $leaks = array_values(array_unique($leaks));
        sort($leaks);

        return new DevLeakResult($leaks, $ownFiles, $packages, count($this->namespaces) + count($this->classPrefixes) + count($this->classes) + count($this->functions));
    }

    public function enterNode(Node $node)
    {
        if ($node instanceof ClassLike && $node->name !== null && $node->namespacedName !== null) {
            $this->declaredClasses[$node->namespacedName->toString()] = true;
        } elseif ($node instanceof Function_ && $node->namespacedName !== null) {
            $this->declaredFunctions[$node->namespacedName->toString()] = true;
        } elseif ($node instanceof Name) {
            if (!$node->getAttribute('parent') instanceof Namespace_) {
                $this->candidate($node->toString(), $node->getStartLine());

                $resolved = $node->getAttribute('resolvedName');

                if ($resolved instanceof Name) {
                    $this->candidate($resolved->toString(), $node->getStartLine());
                }
            }
        } elseif ($node instanceof FuncCall && $node->name instanceof Name && $node->name->isUnqualified()) {
            $this->candidate($node->name->toString(), $node->getStartLine());
        } elseif ($node instanceof ConstFetch && $node->name instanceof Name && $node->name->isUnqualified()) {
            $this->candidate($node->name->toString(), $node->getStartLine());
        } elseif ($node instanceof String_ && !str_starts_with($this->file, $this->config->vendor . '/')) {
            $value = trim($node->value, '\\');

            if ($value !== '' && preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*$/', $value) === 1) {
                $this->candidateSymbol($value, $node->getStartLine());
            }
        }

        return null;
    }

    private function buildIndex(?callable $tick = null): int
    {
        $devPath = $this->config->absolute($this->config->vendor . '/composer/installed.json');
        $productionPath = $this->config->installedPath();

        if (!is_file($devPath) || !is_file($productionPath)) {
            return 0;
        }

        $dev = $this->readJson($devPath);
        $production = $this->readJson($productionPath);
        $devNames = array_values(array_filter((array) ($dev['dev-package-names'] ?? []), 'is_string'));
        $productionNames = array_map(static fn(array $package): string => (string) ($package['name'] ?? ''), (array) ($production['packages'] ?? []));
        $devOnly = array_values(array_diff($devNames, $productionNames));

        if ($devOnly === []) {
            return 0;
        }

        $packages = [];

        foreach ((array) ($dev['packages'] ?? []) as $package) {
            if (is_array($package) && isset($package['name'])) {
                $packages[(string) $package['name']] = $package;
            }
        }

        $repository = InstalledRepository::fromFile($devPath, $this->config->vendor);

        foreach ($devOnly as $name) {
            if ($tick !== null) {
                $tick();
            }

            $package = $packages[$name] ?? null;

            if (!is_array($package)) {
                continue;
            }

            $autoload = is_array($package['autoload'] ?? null) ? $package['autoload'] : [];

            foreach (['psr-4', 'psr-0'] as $type) {
                foreach ((array) ($autoload[$type] ?? []) as $prefix => $directories) {
                    $prefix = trim((string) $prefix, '\\');

                    if ($prefix === '') {
                        continue;
                    }

                    if ($type === 'psr-0' && !str_contains($prefix, '\\')) {
                        $this->classPrefixes[$prefix] = $name;

                        continue;
                    }

                    $this->namespaces[$prefix] = $name;
                    $roots = [];

                    foreach ((array) $directories as $directory) {
                        if (is_string($directory) && trim($directory) !== '') {
                            $roots[] = Path::isAbsolute($directory) ? $directory : $this->config->absolute($repository->directoryFor($name) . '/' . $directory);
                        }
                    }

                    $this->psr4[$prefix] = ['package' => $name, 'paths' => $roots];
                }
            }

            $paths = [];

            foreach (['classmap', 'files'] as $type) {
                foreach ((array) ($autoload[$type] ?? []) as $path) {
                    if (is_string($path) && trim($path) !== '') {
                        $paths[] = $path;
                    }
                }
            }

            if ($paths === []) {
                continue;
            }

            $directory = $repository->directoryFor($name);

            foreach (array_unique($paths) as $path) {
                $absolute = Path::isAbsolute($path) ? $path : $this->config->absolute($directory . '/' . $path);

                foreach ($this->files->files($absolute) as $file) {
                    if (str_ends_with($file, '.php')) {
                        if ($tick !== null) {
                            $tick();
                        }

                        $this->indexFile($file, $name);
                    }
                }
            }
        }

        uksort($this->namespaces, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        uksort($this->classPrefixes, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        uksort($this->psr4, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

        return count($devOnly);
    }

    private function indexFile(string $file, string $package): void
    {
        if (isset($this->indexedFiles[$file])) {
            return;
        }

        $this->indexedFiles[$file] = true;

        $collector = new class($package) extends NodeVisitorAbstract {
            /** @var array<string, string> */
            public array $classes = [];

            /** @var array<string, string> */
            public array $functions = [];

            public function __construct(private readonly string $package) {}

            public function enterNode(Node $node)
            {
                if ($node instanceof ClassLike && $node->name !== null && $node->namespacedName !== null) {
                    $this->classes[$node->namespacedName->toString()] = $this->package;
                } elseif ($node instanceof Function_ && $node->namespacedName !== null) {
                    $this->functions[$node->namespacedName->toString()] = $this->package;
                }

                return null;
            }
        };

        $traverser = new NodeTraverser(new NameResolver());
        $traverser->addVisitor($collector);

        try {
            $ast = $this->parser->parse($this->files->read($file));
            $traverser->traverse($ast ?? []);
        } catch (Throwable) {
            return;
        }

        foreach ($collector->classes as $fqn => $owner) {
            if (!$this->isInternalClass($fqn)) {
                $this->classes[$fqn] = $owner;
            }
        }

        foreach ($collector->functions as $fqn => $owner) {
            if (!$this->isInternalFunction($fqn)) {
                $this->functions[$fqn] = $owner;
            }
        }
    }

    private function scanFile(string $relative): void
    {
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ParentConnectingVisitor());
        $traverser->addVisitor(new NameResolver(null, ['replaceNodes' => false, 'preserveOriginalNames' => true]));
        $traverser->addVisitor($this);

        try {
            $ast = $this->parser->parse($this->files->read($this->config->outputPath() . '/' . $relative));
            $traverser->traverse($ast ?? []);
        } catch (Throwable $exception) {
            throw new ScoperException("Could not inspect {$relative} for dev-only references: {$exception->getMessage()}");
        }
    }

    private function candidate(string $name, int $line): void
    {
        // Only the project's own files can put a dev-only symbol on a runtime path. Production
        // vendor code may mention optional dev symbols in dead files (tests, stubs).
        if (str_starts_with($this->file, $this->config->vendor . '/')) {
            return;
        }

        $package = $this->packageFor($name);

        if ($package !== null) {
            $this->candidates[] = ['file' => $this->file, 'line' => $line, 'name' => $name, 'package' => $package];
        }
    }

    /**
     * String literals are matched against declared symbols only: a namespace-shaped string is too
     * weak a signal (hook names, option keys) to fail a build on. PSR-4 classes are resolved by
     * their file path on demand, so the whole dev tree is not parsed.
     */
    private function candidateSymbol(string $name, int $line): void
    {
        if (str_starts_with($this->file, $this->config->vendor . '/')) {
            return;
        }

        $package = $this->resolveSymbol($name);

        if ($package !== null) {
            $this->candidates[] = ['file' => $this->file, 'line' => $line, 'name' => $name, 'package' => $package];
        }
    }

    private function resolveSymbol(string $name): ?string
    {
        if (isset($this->classes[$name])) {
            return $this->classes[$name];
        }

        if (isset($this->functions[$name])) {
            return $this->functions[$name];
        }

        foreach ($this->psr4 as $prefix => $entry) {
            if (!str_starts_with($name, $prefix . '\\')) {
                continue;
            }

            $suffix = substr($name, strlen($prefix) + 1);

            foreach ($entry['paths'] as $directory) {
                $file = $directory . '/' . str_replace('\\', '/', $suffix) . '.php';

                if (is_file($file)) {
                    $this->indexFile($file, $entry['package']);
                }
            }

            break;
        }

        return $this->classes[$name] ?? $this->functions[$name] ?? null;
    }

    private function packageFor(string $name): ?string
    {
        if (isset($this->classes[$name])) {
            return $this->classes[$name];
        }

        if (isset($this->functions[$name])) {
            return $this->functions[$name];
        }

        foreach ($this->namespaces as $prefix => $package) {
            if ($name === $prefix || str_starts_with($name, $prefix . '\\')) {
                return $package;
            }
        }

        foreach ($this->classPrefixes as $prefix => $package) {
            if (str_starts_with($name, $prefix)) {
                return $package;
            }
        }

        return null;
    }

    private function isInternalClass(string $fqn): bool
    {
        $exists = class_exists($fqn, false) || interface_exists($fqn, false) || trait_exists($fqn, false) || enum_exists($fqn, false);

        return $exists && (new ReflectionClass($fqn))->isInternal();
    }

    private function isInternalFunction(string $fqn): bool
    {
        return function_exists($fqn) && (new ReflectionFunction($fqn))->isInternal();
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        $json = json_decode((string) file_get_contents($path), true);

        return is_array($json) ? $json : [];
    }
}
