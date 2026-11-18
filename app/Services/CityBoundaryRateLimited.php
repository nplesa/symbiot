<?php

namespace App\Services;

use RuntimeException;

/** Too many new cities asked for in a minute (stored boundaries are always served). */
class CityBoundaryRateLimited extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct('Too many new city boundaries requested.');
    }
}
