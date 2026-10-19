<?php

declare(strict_types=1);

namespace CleanPlugins\Scoper\Exception;

use RuntimeException;
use Throwable;

/**
 * A build failure with optional detail lines, so the console can print one coherent block.
 */
class ScoperException extends RuntimeException
{
    /** @param list<string> $details */
    public function __construct(string $message, public readonly array $details = [], ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
