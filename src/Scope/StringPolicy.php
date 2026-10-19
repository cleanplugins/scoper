<?php

declare(strict_types=1);

namespace CleanPlugins\Scoper\Scope;

use CleanPlugins\Scoper\Config\Policy;

/**
 * What to do with a string literal.
 *
 * An exact symbol name — global or namespace-qualified — is a real reference and is rewritten. A
 * literal that is merely a *prefix* of one (an introspection literal such as `'Less_Tree_'`) is
 * ambiguous: it could be introspection, or dynamic name construction. It must therefore be declared
 * under `ignore` with `"type": "string"`; an undeclared one fails the build.
 */
final class StringPolicy
{
    /** @var array<string, array<string, true>> */
    public array $kept = [];

    public function __construct(private readonly Policy $policy, private readonly SymbolTable $symbols) {}

    /** @return array{action: string, to: ?string, reason: string} */
    public function decide(string $package, string $value): array
    {
        $rule = $this->policy->stringRule('ignore', $package, $value);

        if ($rule !== null) {
            $this->kept[$rule['scope']][$value] = true;

            return ['action' => 'keep', 'to' => null, 'reason' => $rule['reason']];
        }

        // A protected symbol keeps its name, so a literal naming it must keep it too.
        $protected = $this->symbols->protectedGlobals();

        if (isset($protected[$value])) {
            return ['action' => 'keep', 'to' => null, 'reason' => $protected[$value]];
        }

        $resolved = $this->symbols->resolve($value);

        if ($resolved !== null) {
            return ['action' => 'rewrite', 'to' => $resolved, 'reason' => 'names a symbol we rename'];
        }

        if ($this->symbols->symbolIshPrefix($value)) {
            return ['action' => 'undeclared', 'to' => null, 'reason' => 'a prefix of a symbol we rename, but not a symbol itself'];
        }

        return ['action' => 'ignore', 'to' => null, 'reason' => ''];
    }
}
