<?php

declare(strict_types=1);

namespace CleanPlugins\Scoper\Verify;

use CleanPlugins\Scoper\Config\Config;
use CleanPlugins\Scoper\Config\Glob;
use CleanPlugins\Scoper\Support\Filesystem;
use CleanPlugins\Scoper\Support\Path;

/**
 * Fails the build when the scoped dependency tree leaks symbols it was supposed to isolate.
 *
 * Three things defeat isolation: a namespace that was never prefixed, a global declaration
 * (`class`, `function`, `const`, `define()`, `class_alias()`) which no namespace can reach, and a
 * string literal that names a symbol. Excluded packages and guard-protected declarations are
 * allowed, because the config says why.
 */
final class LeakScanner
{
    public function __construct(private readonly Config $config, private readonly Filesystem $files) {}

    public function run(): ScanResult
    {
        $buildDir = $this->config->outputPath();
        $vendor = $this->config->vendor;
        $policy = $this->config->policy;
        $prefix = $this->config->prefix;
        $map = is_file($this->config->mapPath()) ? (json_decode((string) file_get_contents($this->config->mapPath()), true) ?: []) : [];
        $ignoredPaths = $policy->ignoredPaths();
        $allowedGlobals = array_merge(array_keys($policy->ignoredGlobals), array_diff(array_keys($policy->ignoredNamespaces), array_keys($policy->overrideNamespaces)));

        foreach (array_keys($map['protected_globals'] ?? []) as $symbol) {
            $allowedGlobals[] = (string) $symbol;
        }

        $ownPrefixes = $this->ownPrefixes();
        $globalPrefixes = array_values($prefix->globals);
        $allowedSymbol = static function (string $name) use ($allowedGlobals, $globalPrefixes, $prefix): bool {
            if (in_array($name, $allowedGlobals, true)) {
                return true;
            }

            if (str_starts_with($name, $prefix->namespace . '\\')) {
                return true;
            }

            foreach ($globalPrefixes as $rendered) {
                if (str_starts_with($name, $rendered)) {
                    return true;
                }
            }

            return false;
        };

        $leaks = [];
        $files = 0;
        $allowedNamespaces = array_merge([$prefix->namespace], $ownPrefixes, array_diff(array_keys($policy->ignoredNamespaces), array_keys($policy->overrideNamespaces)));

        foreach ($this->files->files($buildDir . '/' . $vendor) as $path) {
            if (!str_ends_with($path, '.php')) {
                continue;
            }

            $relative = Path::relative($buildDir, $path);

            if (Glob::isIgnored($relative, $ignoredPaths)) {
                continue;
            }

            ++$files;

            $tokens = token_get_all((string) file_get_contents($path));
            $namespace = '';
            $depth = 0;
            $classDepths = [];

            foreach ($tokens as $index => $token) {
                if (!is_array($token)) {
                    if ($token === '{') {
                        ++$depth;
                    } elseif ($token === '}') {
                        --$depth;

                        while ($classDepths !== [] && end($classDepths) >= $depth) {
                            array_pop($classDepths);
                        }
                    }

                    continue;
                }

                switch ($token[0]) {
                    case T_NAMESPACE:
                        $parts = [];

                        for ($i = $index + 1, $max = count($tokens); $i < $max; ++$i) {
                            if ($tokens[$i] === ';' || $tokens[$i] === '{') {
                                break;
                            }

                            if (is_array($tokens[$i]) && self::isNameToken($tokens[$i])) {
                                $parts[] = $tokens[$i][1];
                            }
                        }

                        $namespace = implode('', $parts);
                        break;

                    case T_CLASS:
                    case T_INTERFACE:
                    case T_TRAIT:
                    case T_ENUM:
                        $name = self::next($tokens, $index);

                        if (!is_array($name) || $name[0] !== T_STRING) {
                            break;
                        }

                        // A global class cannot be isolated by a namespace, so it must have been prefixed.
                        if ($namespace === '' && $classDepths === [] && !$allowedSymbol($name[1])) {
                            $leaks[] = sprintf('%s:%d global class %s', $relative, $token[2], $name[1]);
                        }

                        $classDepths[] = $depth;
                        break;

                    case T_FUNCTION:
                        if (self::previous($tokens, $index) === 'use') {
                            break;
                        }

                        $name = self::next($tokens, $index);

                        if ($name === '&') {
                            $name = self::next($tokens, array_search($name, $tokens, true) ?: $index);
                        }

                        if (is_array($name) && $name[0] === T_STRING && $namespace === '' && $classDepths === [] && !$allowedSymbol($name[1])) {
                            $leaks[] = sprintf('%s:%d global function %s()', $relative, $token[2], $name[1]);
                        }
                        break;

                    case T_CONST:
                        if (self::previous($tokens, $index) === 'use' || $namespace !== '' || $classDepths !== []) {
                            break;
                        }

                        $name = self::next($tokens, $index);

                        if (is_array($name) && !$allowedSymbol($name[1])) {
                            $leaks[] = sprintf('%s:%d global const %s', $relative, $token[2], $name[1]);
                        }
                        break;

                    case T_STRING:
                        $lower = strtolower($token[1]);

                        if ($lower === 'class_alias' && !in_array(self::stringOf(self::next($tokens, $index)), $allowedGlobals, true)) {
                            $leaks[] = sprintf('%s:%d class_alias() call', $relative, $token[2]);
                        }

                        if ($lower !== 'define') {
                            break;
                        }

                        $open = self::next($tokens, $index);

                        if (self::stringOf($open) !== '(') {
                            break;
                        }

                        $name = self::stringOf(self::next($tokens, array_search($open, $tokens, true) ?: $index));

                        if ($name !== '' && !str_starts_with($name, $prefix->namespace . '\\') && !$allowedSymbol($name)) {
                            $leaks[] = sprintf('%s:%d define(%s)', $relative, $token[2], $name);
                        }
                        break;
                }
            }

            if ($namespace === '') {
                continue;
            }

            foreach ($allowedNamespaces as $allowed) {
                if ($namespace === $allowed || str_starts_with($namespace, $allowed . '\\')) {
                    continue 2;
                }
            }

            $leaks[] = sprintf('%s namespace %s was never prefixed', $relative, $namespace);
        }

        return new ScanResult($files, $leaks);
    }

    /** @return list<string> */
    private function ownPrefixes(): array
    {
        $autoload = $this->config->composerJson['autoload'] ?? [];
        $prefixes = [];

        foreach (['psr-4', 'psr-0'] as $type) {
            foreach (array_keys((array) ($autoload[$type] ?? [])) as $namespace) {
                $namespace = trim((string) $namespace, '\\');

                if ($namespace !== '') {
                    $prefixes[] = $namespace;
                }
            }
        }

        return array_values(array_unique($prefixes));
    }

    /** @return array{0:int,1:string,2:int}|string|null */
    private static function next(array $tokens, int $index): array|string|null
    {
        for ($i = $index + 1, $max = count($tokens); $i < $max; ++$i) {
            if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $tokens[$i];
        }

        return null;
    }

    /** @return array{0:int,1:string,2:int}|string|null */
    private static function previous(array $tokens, int $index): array|string|null
    {
        for ($i = $index - 1; $i >= 0; --$i) {
            if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $tokens[$i];
        }

        return null;
    }

    private static function isNameToken(array $token): bool
    {
        return in_array($token[0], [T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true);
    }

    private static function stringOf(array|string|null $token): string
    {
        return is_array($token) ? $token[1] : (string) $token;
    }
}
