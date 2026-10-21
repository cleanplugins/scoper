<?php

declare(strict_types=1);

namespace CleanPlugins\Scoper\Scope;

use CleanPlugins\Scoper\Config\Config;
use CleanPlugins\Scoper\Config\Glob;
use CleanPlugins\Scoper\Exception\ScoperException;
use CleanPlugins\Scoper\Support\Filesystem;
use CleanPlugins\Scoper\Support\InstalledRepository;
use CleanPlugins\Scoper\Support\Path;
use CleanPlugins\Scoper\Support\Progress;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ElseIf_;
use PhpParser\Node\Stmt\If_;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use Throwable;

/**
 * Scopes a staged build tree in place, by splicing the original file text.
 *
 * Three phases, so a failure never leaves a half-rewritten tree: inventory, plan (with a syntax and
 * survivor check for every file), then apply. Only absolute references are rewritten, and every
 * replacement stays on its original line, so line numbers are identical in the output and stack
 * traces map back to the source commit.
 */
final class Scoper
{
    public function __construct(private readonly Config $config, private readonly Filesystem $files) {}

    public function run(?Progress $progress = null): ScopeResult
    {
        $buildDir = $this->config->outputPath();

        if (!is_dir($buildDir)) {
            throw new ScoperException("Build directory not found: {$buildDir}");
        }

        if ($buildDir === $this->config->projectDir || is_dir($buildDir . '/.git')) {
            throw new ScoperException('Refusing to rewrite the checkout; pass a staged build directory instead');
        }

        $prefix = $this->config->prefix;
        $policy = $this->config->policy;
        $vendor = $this->config->vendor;
        $ownNamespaces = $this->ownNamespaces();
        $ignoredPatterns = $policy->ignoredPaths();
        $keptNamespaces = array_diff(array_keys($policy->ignoredNamespaces), array_keys($policy->overrideNamespaces));

        $installed = InstalledRepository::fromFile($this->config->installedPath(), $vendor);
        $installedNames = $installed->names();

        if ($installedNames === []) {
            foreach (glob($buildDir . '/' . $vendor . '/*/*', GLOB_ONLYDIR) ?: [] as $directory) {
                $relative = Path::relative($buildDir, $directory);
                $installedNames[] = substr($relative, strlen($vendor) + 1);
            }

            sort($installedNames);
        }

        $ignoredPackageNames = [];

        foreach (array_keys($policy->ignoredPackages) as $pattern) {
            foreach ($policy->packagesMatching($installedNames, $pattern) as $name) {
                $ignoredPackageNames[$name] = true;
            }
        }

        $ignoredPackageNames = array_keys($ignoredPackageNames);
        sort($ignoredPackageNames);

        $phpFiles = $this->phpFiles($buildDir);
        $progress?->total(count($phpFiles) * 2);
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $isOwnFile = static fn(string $relative): bool => !str_starts_with($relative, $vendor . '/');

        $inventory = new Inventory($ignoredPatterns, $isOwnFile, $this->guardNames(...));

        foreach ($phpFiles as $relative) {
            $progress?->advance();
            try {
                $ast = $parser->parse($this->files->read($buildDir . '/' . $relative));
            } catch (Throwable $exception) {
                throw new ScoperException("Could not parse {$relative}: {$exception->getMessage()}");
            }

            if ($ast === null) {
                continue;
            }

            $inventory->setFile($relative);
            (new NodeTraverser($inventory))->traverse($ast);
        }

        $guardedReport = $inventory->guarded;
        $declaredNamespaces = $inventory->namespaces;
        $protectedNamespaces = array_merge($ownNamespaces, $keptNamespaces);

        foreach (array_keys($inventory->protectedNamespaces) as $namespace) {
            $protectedNamespaces[] = $namespace;
        }

        $declaredGlobals = [];
        $protectedGlobals = [];

        foreach ($inventory->globals as $symbol => $declaration) {
            // A guarded declaration is protected unless the policy explicitly overrides that symbol.
            if ($declaration['guarded'] && !isset($policy->overrideGlobals[$symbol])) {
                $protectedGlobals[$symbol] = 'guarded by ' . $declaration['guard'] . ' in ' . $declaration['file'];

                continue;
            }

            $declaredGlobals[$symbol] = $declaration + ['to' => $prefix->render($symbol)];
        }

        foreach (array_keys($policy->ignoredGlobals) as $symbol) {
            $protectedGlobals[(string) $symbol] = (string) $policy->ignoredGlobals[$symbol];
        }

        $symbols = new SymbolTable($prefix, $declaredNamespaces, $protectedNamespaces, $declaredGlobals, $protectedGlobals);
        $stringPolicy = new StringPolicy($policy, $symbols);

        $filesScanned = 0;
        $alreadyPrefixed = null;
        $stringReport = ['rewrite' => [], 'keep' => [], 'undeclared' => [], 'unresolvable' => []];
        $plan = [];
        $usedPaths = [];

        foreach ($phpFiles as $relative) {
            $progress?->advance();
            $code = $this->files->read($buildDir . '/' . $relative);

            if ($alreadyPrefixed === null && str_contains($code, $prefix->namespace . '\\')) {
                $alreadyPrefixed = $relative;
            }

            foreach ($ignoredPatterns as $pattern) {
                if (!isset($usedPaths[$pattern]) && Glob::isIgnored($relative, [$pattern])) {
                    $usedPaths[$pattern] = true;
                }
            }

            ++$filesScanned;

            try {
                $ast = $parser->parse($code);
            } catch (Throwable $exception) {
                throw new ScoperException("Could not parse {$relative}: {$exception->getMessage()}");
            }

            if ($ast === null) {
                continue;
            }

            $rewriter = new Rewriter($symbols, $stringPolicy, $prefix->namespace);
            $rewriter->setFile($relative, $this->packageFor($relative, $vendor) ?? '');
            (new NodeTraverser($rewriter))->traverse($ast);

            foreach ($rewriter->strings as $string) {
                if ($string['action'] === 'ignore') {
                    continue;
                }

                if ($string['action'] === 'rewrite' && $string['to'] === null) {
                    $stringReport['unresolvable'][] = ['file' => $relative] + $string;

                    continue;
                }

                $stringReport[$string['action']][] = ['file' => $relative] + $string;
            }

            if ($rewriter->blocked !== []) {
                throw new ScoperException(sprintf('%s has grouped imports whose prefix is not scoped while a member is (%s); ungroup them or exclude the package', $relative, implode(', ', array_unique($rewriter->blocked))));
            }

            if ($rewriter->edits === []) {
                continue;
            }

            $rewritten = $this->apply($code, $rewriter->edits);

            // Syntax gate, in-process: TOKEN_PARSE runs PHP's own parser, i.e. what `php -l` would check.
            try {
                token_get_all($rewritten, TOKEN_PARSE);
            } catch (\ParseError $error) {
                throw new ScoperException(sprintf('%s does not parse after rewriting: %s', $relative, $error->getMessage()));
            }

            $verify = new Rewriter($symbols, $stringPolicy, $prefix->namespace, true);
            $verify->setFile($relative, $this->packageFor($relative, $vendor) ?? '');
            (new NodeTraverser($verify))->traverse($parser->parse($rewritten) ?? []);

            if ($verify->survivors !== []) {
                throw new ScoperException(sprintf('%s still references %s after rewriting', $relative, implode(', ', array_unique($verify->survivors))));
            }

            $plan[$relative] = ['code' => $rewritten, 'edits' => $rewriter->edits];
        }

        if ($alreadyPrefixed !== null) {
            throw new ScoperException("{$alreadyPrefixed} already contains {$prefix->namespace}\\; re-stage the build tree before running the scoper");
        }

        if ($stringReport['unresolvable'] !== []) {
            throw new ScoperException(sprintf('%d configured string rewrite(s) do not name a symbol we rename', count($stringReport['unresolvable'])), array_map(static fn(array $entry): string => "{$entry['file']}:{$entry['line']} {$entry['value']}", $stringReport['unresolvable']));
        }

        if ($stringReport['undeclared'] !== []) {
            throw new ScoperException(sprintf('%d string literal(s) are a prefix of a symbol we rename, so the intent cannot be inferred; declare each under ignore with "type": "string"', count($stringReport['undeclared'])), array_map(static fn(array $entry): string => "{$entry['file']}:{$entry['line']} {$entry['value']}", $stringReport['undeclared']));
        }

        $editsByFile = [];

        foreach ($plan as $relative => $item) {
            $this->files->write($buildDir . '/' . $relative, $item['code']);
            $editsByFile[$relative] = array_map(static fn(array $edit): array => ['start' => $edit['start'], 'end' => $edit['end'], 'from' => $edit['from'], 'to' => $edit['to']], $item['edits']);
        }

        $excludedNamespaces = array_values(array_unique(array_merge($ownNamespaces, $keptNamespaces)));
        sort($excludedNamespaces);

        $map = [
            'prefix' => $prefix->namespace,
            'global_prefixes' => $prefix->globals,
            'excluded' => [
                'packages' => $ignoredPackageNames,
                'removed' => array_keys($policy->removedPackages),
                'paths' => $policy->ignoredPaths(),
                'namespaces' => $excludedNamespaces,
            ],
            'namespaces' => $declaredNamespaces,
            'globals' => $declaredGlobals,
            'protected_globals' => $protectedGlobals,
            'guarded' => $guardedReport,
            'strings' => $stringReport,
            'edits' => $editsByFile,
        ];

        $this->files->write($this->config->mapPath(), json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

        $warnings = [];
        $unused = [];

        if (extension_loaded('xdebug') && !in_array(ini_get('xdebug.mode'), ['off', ''], true)) {
            $warnings[] = 'Xdebug is loaded; this run is roughly 20x slower (use php -n or -d xdebug.mode=off)';
        }

        $declaredNamespaceKeys = array_merge(array_keys($inventory->namespaces), array_keys($inventory->protectedNamespaces));

        foreach (array_keys($policy->ignoredPackages) as $pattern) {
            if ($policy->packagesMatching($installedNames, $pattern) === []) {
                $unused[] = "ignore package \"{$pattern}\" matched nothing; remove the entry";
            }
        }

        foreach (array_keys($policy->ignoredNamespaces) as $namespace) {
            if (!in_array($namespace, $declaredNamespaceKeys, true)) {
                $unused[] = "ignore namespace \"{$namespace}\" matched nothing; remove the entry";
            }
        }

        foreach (array_keys($policy->ignoredGlobals) as $symbol) {
            if (!isset($inventory->globals[$symbol])) {
                $unused[] = "ignore global \"{$symbol}\" matched nothing; remove the entry";
            }
        }

        foreach ($policy->ignoredStrings as $scope => $rules) {
            foreach (array_keys($rules) as $literal) {
                if (!isset($stringPolicy->kept[$scope][$literal])) {
                    $unused[] = sprintf('ignore string "%s" in %s matched nothing; remove the entry', $literal, $scope === '*' ? 'any package' : $scope);
                }
            }
        }

        foreach (array_keys($policy->ignoredPaths) as $pattern) {
            if (!isset($usedPaths[$pattern])) {
                $unused[] = "ignore path \"{$pattern}\" matched nothing; remove the entry";
            }
        }

        $editsTotal = array_sum(array_map(static fn(array $item): int => count($item['edits']), $plan));
        $styleCounts = array_fill_keys(array_keys($prefix->globals), 0);

        foreach (array_keys($declaredGlobals) as $symbol) {
            $style = $prefix->style((string) $symbol);
            $styleCounts[$style] = ($styleCounts[$style] ?? 0) + 1;
        }

        $prefixRows = [[$prefix->namespace . '\\', sprintf('%d namespaces', count($declaredNamespaces))]];

        foreach ($prefix->globals as $style => $rendered) {
            $prefixRows[] = [$rendered, sprintf('%d globals', $styleCounts[$style] ?? 0)];
        }

        $namespaceRows = [];

        foreach (array_keys($declaredNamespaces) as $namespace) {
            $namespaceRows[] = [(string) $namespace, $prefix->namespace . '\\' . $namespace];
        }

        sort($namespaceRows);

        $globalRows = [];

        foreach ($declaredGlobals as $symbol => $declaration) {
            $globalRows[] = [(string) $symbol, (string) $declaration['to']];
        }

        usort($globalRows, static fn(array $a, array $b): int => strcmp($a[0], $b[0]));

        $stringRows = array_map(static fn(array $entry): array => [sprintf('%s:%d', $entry['file'], $entry['line']), $entry['value']], $stringReport['keep']);

        $protectedRows = [];

        foreach ($protectedGlobals as $symbol => $reason) {
            $protectedRows[] = [(string) $symbol, (string) $reason];
        }

        $guardedRows = [];

        foreach ($guardedReport as $entry) {
            $guardedRows[] = [$entry['symbol'], sprintf('%s at %s:%d', $entry['guard'], $entry['file'], $entry['line'])];
        }

        $sections = [['type' => 'table', 'title' => 'Prefixes', 'rows' => $prefixRows], ['type' => 'table', 'title' => 'Namespaces', 'rows' => $namespaceRows], ['type' => 'table', 'title' => 'Globals', 'rows' => $globalRows], ['type' => 'list', 'title' => 'Left alone', 'items' => $excludedNamespaces], ['type' => 'table', 'title' => 'Strings kept', 'rows' => $stringRows], ['type' => 'table', 'title' => 'Protected', 'rows' => $protectedRows], ['type' => 'table', 'title' => 'Guarded', 'rows' => $guardedRows]];

        return new ScopeResult($map, $sections, $warnings, $unused, count($declaredNamespaces), count($declaredGlobals), $editsTotal, $filesScanned, count($plan), Path::relative($this->config->projectDir, $this->config->mapPath()));
    }

    /** @return list<string> */
    private function ownNamespaces(): array
    {
        $autoload = $this->config->composerJson['autoload'] ?? [];
        $namespaces = [];

        foreach (['psr-4', 'psr-0'] as $type) {
            foreach (array_keys((array) ($autoload[$type] ?? [])) as $namespace) {
                $namespace = trim((string) $namespace, '\\');

                if ($namespace !== '') {
                    $namespaces[] = $namespace;
                }
            }
        }

        return array_values(array_unique($namespaces));
    }

    /** @return list<string> */
    private function phpFiles(string $buildDir): array
    {
        $files = [];

        foreach ($this->files->files($buildDir) as $file) {
            if (str_ends_with($file, '.php')) {
                $files[] = Path::relative($buildDir, $file);
            }
        }

        sort($files);

        return $files;
    }

    private function packageFor(string $relative, string $vendor): ?string
    {
        if (preg_match('#^' . preg_quote($vendor, '#') . '/([^/]+/[^/]+)/#', $relative, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /** Existence checks that guard a declaration of the same name: "provide this if missing". */
    private function guardNames(Node $condition): array
    {
        $tests = [$condition];

        if ($condition instanceof Expr\BooleanNot) {
            $tests[] = $condition->expr;
        }

        if ($condition instanceof Expr\BinaryOp\Identical || $condition instanceof Expr\BinaryOp\NotIdentical) {
            $tests[] = $condition->left;
            $tests[] = $condition->right;
        }

        $names = [];

        foreach ($tests as $test) {
            if (!($test instanceof FuncCall) || !($test->name instanceof Name)) {
                continue;
            }

            $function = strtolower($test->name->toString());

            if (!in_array($function, ['function_exists', 'class_exists', 'interface_exists', 'trait_exists', 'enum_exists', 'defined'], true)) {
                continue;
            }

            $argument = $test->getArgs()[0]->value ?? null;

            if ($argument instanceof String_) {
                $names[] = ltrim($argument->value, '\\');
            }
        }

        return $names;
    }

    /** Applies edits back-to-front; every replacement stays within its line, so line numbers never move. */
    private function apply(string $code, array $edits): string
    {
        usort($edits, static fn(array $a, array $b): int => $b['start'] <=> $a['start']);

        foreach ($edits as $edit) {
            $code = substr_replace($code, $edit['to'], $edit['start'], $edit['end'] - $edit['start'] + 1);
        }

        return $code;
    }
}
