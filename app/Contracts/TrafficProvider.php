<?php

namespace App\Contracts;

interface TrafficProvider
{
    public function flowTile(int $z, int $x, int $y): string;
}
