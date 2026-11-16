<?php

namespace Consys\Ecosystem\Directory;

use InvalidArgumentException;

/**
 * A directory payload without one of the keys every payload of its kind carries.
 */
class InvalidPayload extends InvalidArgumentException
{
    public static function missing(string $kind, string $key): self
    {
        return new self("The {$kind} payload has no {$key}.");
    }
}
