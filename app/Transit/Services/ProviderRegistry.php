<?php

namespace App\Transit\Services;

use App\Transit\Contracts\TransitProvider;
use App\Transit\Models\TransitFeed;
use RuntimeException;

class ProviderRegistry
{
    public function for(TransitFeed $feed): TransitProvider
    {
        $class = config('transit.providers')[$feed->provider] ?? null;
        if ($class === null) {
            throw new RuntimeException("No transit provider registered for \"{$feed->provider}\".");
        }

        return app($class);
    }
}
