<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Classes;

use Winter\Storm\Exception\SystemException;

/**
 * A brick type definition is invalid (a developer error in a plugin or theme).
 */
class InvalidBrickException extends SystemException
{
    /**
     * The problem alone, without the code and file of the message.
     */
    public string $problem = '';

    public static function make(string $code, string $source, string $problem): self
    {
        $exception = new self(sprintf('Brick type "%s" (%s): %s', $code, $source, $problem));
        $exception->problem = $problem;

        return $exception;
    }
}
