<?php

declare(strict_types=1);

namespace CleanPlugins\Scoper\Scope;

use Closure;
use CleanPlugins\Scoper\Config\Glob;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ElseIf_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeVisitorAbstract;

/**
 * Pass 1: what may be rewritten, and what deliberately may not.
 *
 * Declarations are read from the vendor tree. The project's own files and excluded packages are
 * recorded as protected, and so is any global declaration guarded by an existence check on its own
 * name ("provide this if the platform has not").
 */
final class Inventory extends NodeVisitorAbstract
{
    /** @var array<string, array{file: string, start: int, end: int}> */
    public array $namespaces = [];

    /** @var array<string, array{file: string, start: int, end: int}> */
    public array $protectedNamespaces = [];

    /** @var array<string, array{kind: string, file: string, start: int, end: int, guarded: bool, guard: string}> */
    public array $globals = [];

    /** @var list<array{symbol: string, file: string, line: int, guard: string}> */
    public array $guarded = [];

    private string $relative = '';

    private bool $excluded = false;

    private int $namespaceDepth = 0;

    private int $classDepth = 0;

    /** @var list<list<string>> */
    private array $guards = [];

    /**
     * @param list<string> $ignoredPaths
     * @param Closure(string): bool $isOwnFile
     * @param Closure(Node): list<string> $guardNames
     */
    public function __construct(private readonly array $ignoredPaths, private readonly Closure $isOwnFile, private readonly Closure $guardNames) {}

    public function setFile(string $relative): void
    {
        $this->relative = $relative;
        $this->excluded = Glob::isIgnored($relative, $this->ignoredPaths) || ($this->isOwnFile)($relative);
        $this->namespaceDepth = 0;
        $this->classDepth = 0;
        $this->guards = [];
    }

    public function enterNode(Node $node)
    {
        if ($node instanceof If_ || $node instanceof ElseIf_) {
            $this->guards[] = ($this->guardNames)($node->cond);

            return null;
        }

        if ($node instanceof Namespace_) {
            ++$this->namespaceDepth;

            if ($node->name instanceof Name) {
                $target = $this->excluded ? 'protectedNamespaces' : 'namespaces';

                $this->{$target}[$node->name->toString()] = [
                    'file' => $this->relative,
                    'start' => $node->name->getStartFilePos(),
                    'end' => $node->name->getEndFilePos(),
                ];
            }

            return null;
        }

        if ($this->namespaceDepth !== 0 || $this->excluded) {
            return null;
        }

        if ($node instanceof ClassLike) {
            if ($node->name !== null) {
                $this->declare($node->name, 'class', $node->getStartLine());
            }

            ++$this->classDepth;

            return null;
        }

        if ($node instanceof Stmt\Function_) {
            $this->declare($node->name, 'function', $node->getStartLine());

            return null;
        }

        // A global `const` or a `define()` call — but not a class constant, and not one inside a method.
        if ($this->classDepth === 0 && $node instanceof Node\Const_) {
            $this->declare($node->name, 'constant', $node->getStartLine());

            return null;
        }

        if ($this->classDepth === 0 && $node instanceof Expr\FuncCall && $node->name instanceof Name && strtolower($node->name->toString()) === 'define') {
            $argument = $node->getArgs()[0]->value ?? null;

            if ($argument instanceof String_) {
                $this->declare(new Identifier($argument->value, $argument->getAttributes()), 'constant', $node->getStartLine());
            }
        }

        return null;
    }

    public function leaveNode(Node $node)
    {
        if ($node instanceof If_ || $node instanceof ElseIf_) {
            array_pop($this->guards);
        } elseif ($node instanceof Namespace_) {
            --$this->namespaceDepth;
        } elseif ($node instanceof ClassLike) {
            --$this->classDepth;
        }

        return null;
    }

    private function declare(Identifier $name, string $kind, int $line): void
    {
        $guarded = [];

        foreach ($this->guards as $names) {
            foreach ($names as $candidate) {
                if (strcasecmp($candidate, $name->toString()) === 0) {
                    $guarded[] = $candidate;
                }
            }
        }

        $this->globals[$name->toString()] = [
            'kind' => $kind,
            'file' => $this->relative,
            'start' => $name->getStartFilePos(),
            'end' => $name->getEndFilePos(),
            'guarded' => $guarded !== [],
            'guard' => implode(', ', $guarded),
        ];

        if ($guarded !== []) {
            $this->guarded[] = [
                'symbol' => $name->toString(),
                'file' => $this->relative,
                'line' => $line,
                'guard' => implode(', ', $guarded),
            ];
        }
    }
}
