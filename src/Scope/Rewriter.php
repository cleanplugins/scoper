<?php

declare(strict_types=1);

namespace CleanPlugins\Scoper\Scope;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeVisitorAbstract;

/**
 * Finds absolute references to declared symbols, and the declarations themselves.
 *
 * The same visitor plans the edits and verifies them, so the decision cannot drift between the two.
 * Only absolute references are rewritten: a namespace declaration, `use X\Y`, a fully qualified
 * name, and a name in a file with no namespace declaration. Short names and relative names follow
 * their import or the file's namespace, which is rewritten for them.
 */
final class Rewriter extends NodeVisitorAbstract
{
    /** @var list<array{start: int, end: int, from: string, to: string}> */
    public array $edits = [];

    /** @var list<string> */
    public array $survivors = [];

    /** @var list<string> */
    public array $blocked = [];

    /** @var list<array{line: int, value: string, action: string, to: ?string, reason: string}> */
    public array $strings = [];

    private int $absoluteDepth = 0;

    private int $namespaceDepth = 0;

    private int $classDepth = 0;

    private string $relative = '';

    private string $package = '';

    public function __construct(private readonly SymbolTable $symbols, private readonly StringPolicy $stringPolicy, private readonly string $prefix, private readonly bool $verify = false) {}

    public function setFile(string $relative, string $package): void
    {
        $this->relative = $relative;
        $this->package = $package;
        $this->namespaceDepth = 0;
        $this->classDepth = 0;
        $this->absoluteDepth = 0;
    }

    public function enterNode(Node $node)
    {
        if ($node instanceof GroupUse) {
            ++$this->absoluteDepth;

            $prefix = $node->prefix->toString();

            if ($this->symbols->resolve($prefix) !== null) {
                $this->name($node->prefix);
            } else {
                // Members are relative, so a group whose prefix is not ours cannot be rewritten member by
                // member — but if a member *is* ours, leaving the group alone would reference a name that no
                // longer exists. Report it rather than ship it.
                foreach ($node->uses as $use) {
                    $combined = $prefix . '\\' . $use->name->toString();

                    if ($this->symbols->resolve($combined) !== null) {
                        $this->blocked[] = $combined;

                        break;
                    }
                }
            }

            return null;
        }

        if ($node instanceof Use_) {
            ++$this->absoluteDepth;

            foreach ($node->uses as $use) {
                $this->name($use->name);
            }

            return null;
        }

        if ($node instanceof Namespace_) {
            ++$this->namespaceDepth;

            if ($node->name instanceof Name) {
                $this->name($node->name);
            }

            return null;
        }

        if ($node instanceof Name) {
            if ($this->absoluteDepth > 0) {
                return null;
            }

            if ($node->isFullyQualified() || $this->namespaceDepth === 0) {
                $this->name($node);
            }

            return null;
        }

        if ($this->namespaceDepth === 0) {
            if ($node instanceof ClassLike) {
                if ($node->name !== null) {
                    $this->declaration($node->name);
                }

                ++$this->classDepth;
            } elseif ($node instanceof Stmt\Function_) {
                $this->declaration($node->name);
            } elseif ($this->classDepth === 0 && $node instanceof Node\Const_) {
                // A global `const`, not a class constant.
                $this->declaration($node->name);
            } elseif ($this->classDepth === 0 && $node instanceof Expr\FuncCall && $node->name instanceof Name && strtolower($node->name->toString()) === 'define' && ($node->getArgs()[0]->value ?? null) instanceof String_) {
                /** @var String_ $argument */
                $argument = $node->getArgs()[0]->value;
                $this->declaration(new Identifier($argument->value, $argument->getAttributes()), $argument);
            }
        }

        if (!$this->verify && $node instanceof String_) {
            $decision = $this->stringPolicy->decide($this->package, $node->value);

            if (in_array($decision['action'], ['rewrite', 'undeclared', 'keep'], true)) {
                $this->strings[] = [
                    'line' => $node->getStartLine(),
                    'value' => $node->value,
                    'action' => $decision['action'],
                    'to' => $decision['to'],
                    'reason' => $decision['reason'],
                ];
            }

            if ($decision['action'] === 'rewrite' && $decision['to'] !== null) {
                // Replace the literal's contents, keeping its quotes.
                $this->edits[] = [
                    'start' => $node->getStartFilePos() + 1,
                    'end' => $node->getEndFilePos() - 1,
                    'from' => $node->value,
                    'to' => $decision['to'],
                ];
            }
        }

        return null;
    }

    public function leaveNode(Node $node)
    {
        if ($node instanceof GroupUse || $node instanceof Use_) {
            --$this->absoluteDepth;
        } elseif ($node instanceof Namespace_) {
            --$this->namespaceDepth;
        } elseif ($node instanceof ClassLike) {
            --$this->classDepth;
        }

        return null;
    }

    private function name(Name $name): void
    {
        if ($name->isSpecialClassName()) {
            return;
        }

        $value = $name->toString();

        if (str_starts_with($value, $this->prefix . '\\')) {
            return;
        }

        $resolved = $this->symbols->resolve($value);

        if ($resolved === null) {
            return;
        }

        if ($this->verify) {
            $this->survivors[] = $value;

            return;
        }

        // A fully qualified name keeps its leading backslash.
        $this->edits[] = [
            'start' => $name->getStartFilePos() + ($name->isFullyQualified() ? 1 : 0),
            'end' => $name->getEndFilePos(),
            'from' => $value,
            'to' => $resolved,
        ];
    }

    private function declaration(Identifier $name, ?String_ $literal = null): void
    {
        $value = $name->toString();
        $resolved = $this->symbols->resolve($value);

        if ($resolved === null) {
            return;
        }

        if ($this->verify) {
            $this->survivors[] = $value;

            return;
        }

        $this->edits[] =
            $literal === null
                ? [
                    'start' => $name->getStartFilePos(),
                    'end' => $name->getEndFilePos(),
                    'from' => $value,
                    'to' => $resolved,
                ]
                : [
                    'start' => $literal->getStartFilePos() + 1,
                    'end' => $literal->getEndFilePos() - 1,
                    'from' => $value,
                    'to' => $resolved,
                ];
    }
}
