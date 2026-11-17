<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Classes;

use Winter\Storm\Exception\SystemException;

/**
 * A brick type definition is invalid (a developer error in a plugin or theme).
 */
class InvalidBrickException extends SystemException
{
    public static function make(string $code, string $source, string $problem): self
    {
        return new self(sprintf('Brick type "%s" (%s): %s', $code, $source, $problem));
    }
}
