<?php

declare(strict_types=1);

namespace CleanPlugins\Scoper\Autoload;

use CleanPlugins\Scoper\Config\Config;
use CleanPlugins\Scoper\Exception\ScoperException;
use CleanPlugins\Scoper\Support\Filesystem;
use CleanPlugins\Scoper\Support\InstalledRepository;
use CleanPlugins\Scoper\Support\Path;
use CleanPlugins\Scoper\Support\Progress;
use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

/**
 * Generates the production autoloader for a staged build tree.
 *
 * The release ships no Composer autoloader: the dependencies are scoped in place and this tool
 * writes `<vendor>/autoload.php`, a classmap loader covering exactly the code Composer would have
 * autoloaded — each package's `autoload` rules from `installed.json`, plus the project's own
 * `autoload` rules, with every `files` entry required in Composer's order.
 */
final class Generator
{
    public function __construct(private readonly Config $config, private readonly Filesystem $files) {}

    public function run(?Progress $progress = null): AutoloadResult
    {
        $buildDir = $this->config->outputPath();
        $vendor = $this->config->vendor;
        $policy = $this->config->policy;
        $installedPath = is_file($this->config->installedPath()) ? $this->config->installedPath() : $buildDir . '/' . $vendor . '/composer/installed.json';

        if (!is_file($installedPath)) {
            throw new ScoperException("Composer's installed.json was not saved to {$installedPath}; it is needed for autoload parity");
        }

        $repository = InstalledRepository::fromFile($installedPath, $vendor);
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $paths = [];
        $required = [];

        foreach ($repository->packages() as $package) {
            $directory = $repository->directory($package);
            $resolved = $this->packageRules((array) ($package['autoload'] ?? []), $directory);

            if (is_dir($buildDir . '/' . $directory) || is_file($buildDir . '/' . $directory)) {
                foreach ($resolved['directories'] as $path) {
                    $paths[] = $path;
                }
            }

            foreach ($resolved['required'] as $path) {
                $required[] = $path;
            }
        }

        $root = $this->rootRules((array) ($this->config->composerJson['autoload'] ?? []));

        foreach ($root['directories'] as $path) {
            $paths[] = $path;
        }

        foreach ($root['required'] as $path) {
            $required[] = $path;
        }

        $classmap = [];
        $missing = [];
        $removedPaths = [];
        $declaredPaths = array_values(array_unique($paths));
        $paths = [];

        // Only existing paths are scanned; a path the policy deleted is reported, never read.
        foreach ($declaredPaths as $path) {
            if (is_dir($buildDir . '/' . $path) || is_file($buildDir . '/' . $path)) {
                $paths[] = $path;
            } elseif ($policy->isRemoved($path)) {
                $removedPaths[] = $path;
            } else {
                $missing[] = $path;
            }
        }

        $scanFiles = [];

        foreach ($paths as $path) {
            foreach ($this->phpFiles($buildDir . '/' . $path, $buildDir) as $file) {
                $scanFiles[] = $file;
            }
        }

        $progress?->total(count($scanFiles));

        foreach ($scanFiles as $file) {
            $progress?->advance();

            try {
                $ast = $parser->parse($this->files->read($buildDir . '/' . $file));
            } catch (\Throwable $exception) {
                throw new ScoperException("Could not parse {$file}: {$exception->getMessage()}");
            }

            if ($ast === null) {
                continue;
            }

            $visitor = new class extends NodeVisitorAbstract {
                /** @var list<string> */
                public array $names = [];

                private string $namespace = '';

                public function enterNode(Node $node)
                {
                    if ($node instanceof Node\Stmt\Namespace_) {
                        $this->namespace = $node->name instanceof Name ? $node->name->toString() : '';

                        return null;
                    }

                    if ($node instanceof Node\Stmt\ClassLike && $node->name !== null) {
                        $this->names[] = $this->namespace === '' ? $node->name->toString() : $this->namespace . '\\' . $node->name->toString();
                    }

                    return null;
                }
            };

            (new NodeTraverser($visitor))->traverse($ast);

            foreach ($visitor->names as $name) {
                $relative = Path::relative($buildDir . '/' . $vendor, $buildDir . '/' . $file);

                if (isset($classmap[$name]) && $classmap[$name] !== $relative) {
                    throw new ScoperException("{$name} is declared twice ({$classmap[$name]} and {$file})");
                }

                $classmap[$name] = $relative;
            }
        }

        if ($classmap === []) {
            throw new ScoperException('No classes were found; is the build complete?');
        }

        ksort($classmap);

        $lines = [];
        $lines[] = '<?php';
        $lines[] = '';
        $lines[] = '/**';
        $lines[] = ' * @generated by the build — do not edit.';
        $lines[] = ' *';
        $lines[] = ' * Classmap autoloader for the scoped dependency tree: Composer\'s autoload metadata for the shipped';
        $lines[] = ' * packages, plus the plugin\'s own autoload rules.';
        $lines[] = ' */';
        $lines[] = '';
        $lines[] = 'spl_autoload_register(static function (string $class): void {';
        $lines[] = '    /** @var array<string, string> $classmap */';
        $lines[] = '    static $classmap = [';

        foreach ($classmap as $class => $file) {
            $lines[] = sprintf('        %s => %s,', var_export($class, true), var_export($file, true));
        }

        $lines[] = '    ];';
        $lines[] = '';
        $lines[] = '    if (isset($classmap[$class])) {';
        $lines[] = '        require __DIR__ . \'/\' . $classmap[$class];';
        $lines[] = '    }';
        $lines[] = '});';

        // Composer requires every `files` entry when its autoloader is loaded; so do we. Entries whose
        // file the build deliberately dropped are skipped and reported.
        $skipped = [];
        $filesToRequire = [];

        foreach (array_values(array_unique($required)) as $file) {
            if (is_file($buildDir . '/' . $file)) {
                $filesToRequire[] = $file;

                continue;
            }

            $skipped[] = $file;
        }

        foreach ($filesToRequire as $file) {
            $lines[] = '';
            $lines[] = sprintf("require_once __DIR__ . '/%s';", Path::relative($buildDir . '/' . $vendor, $buildDir . '/' . $file));
        }

        $lines[] = '';

        $this->files->write($buildDir . '/' . $vendor . '/autoload.php', implode(PHP_EOL, $lines));

        $warnings = [];

        if ($missing !== []) {
            $warnings[] = 'declared autoload paths that do not exist: ' . implode(', ', $missing);
        }

        return new AutoloadResult([['type' => 'list', 'title' => 'Scanned', 'items' => array_values(array_unique($paths))], ['type' => 'list', 'title' => 'Required first', 'items' => array_values(array_unique($filesToRequire))], ['type' => 'list', 'title' => 'Not shipped, so not required', 'items' => $skipped], ['type' => 'list', 'title' => 'Removed by policy, so not mapped', 'items' => $removedPaths], ['type' => 'list', 'title' => 'Missing', 'items' => $missing]], $warnings, count($classmap));
    }

    /** @return array{directories: list<string>, required: list<string>} */
    private function packageRules(array $autoload, string $directory): array
    {
        $directories = [];
        $required = [];

        foreach (['psr-4', 'psr-0', 'classmap'] as $key) {
            foreach ((array) ($autoload[$key] ?? []) as $entry) {
                foreach ((array) $entry as $path) {
                    if (!is_string($path)) {
                        continue;
                    }

                    $directories[] = $path === '' ? $directory : $directory . '/' . trim($path, '/\\');
                }
            }
        }

        foreach ((array) ($autoload['files'] ?? []) as $path) {
            if (is_string($path)) {
                $required[] = $directory . '/' . trim($path, '/\\');
            }
        }

        return [
            'directories' => array_values(array_unique($directories)),
            'required' => array_values(array_unique($required)),
        ];
    }

    /** @return array{directories: list<string>, required: list<string>} */
    private function rootRules(array $autoload): array
    {
        $directories = [];
        $required = [];

        foreach (['psr-4', 'psr-0'] as $key) {
            foreach ((array) ($autoload[$key] ?? []) as $entry) {
                foreach ((array) $entry as $path) {
                    if (is_string($path)) {
                        $directories[] = trim($path, '/\\');
                    }
                }
            }
        }

        foreach ((array) ($autoload['classmap'] ?? []) as $path) {
            if (is_string($path)) {
                $directories[] = trim($path, '/\\');
            }
        }

        foreach ((array) ($autoload['files'] ?? []) as $path) {
            if (is_string($path)) {
                $required[] = trim($path, '/\\');
            }
        }

        return [
            'directories' => array_values(array_unique($directories)),
            'required' => array_values(array_unique($required)),
        ];
    }

    /** @return list<string> build-relative PHP files under a path */
    private function phpFiles(string $path, string $buildDir): array
    {
        $files = [];

        foreach ($this->files->files($path) as $file) {
            if (str_ends_with($file, '.php')) {
                $files[] = Path::relative($buildDir, $file);
            }
        }

        sort($files);

        return $files;
    }
}
