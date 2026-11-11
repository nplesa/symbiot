<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class PruneMapTiles extends Command
{
    protected $signature = 'map:prune-tiles {--days=30 : Delete cached tiles not written for this many days}';

    protected $description = 'Deletes old map tiles from the tile proxy disk cache.';

    public function handle(): int
    {
        // The file cache driver never evicts expired entries on its own, so prune by file age.
        $path = config('cache.stores.tiles.path');
        if (! is_string($path) || ! is_dir($path)) {
            $this->info('No tile cache to prune.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays(max(1, (int) $this->option('days')))->getTimestamp();
        $deleted = 0;
        $freedBytes = 0;

        foreach (File::allFiles($path) as $file) {
            if ($file->getMTime() >= $cutoff) {
                continue;
            }

            $size = $file->getSize();
            if (@unlink($file->getPathname())) {
                $deleted++;
                $freedBytes += $size;
            }
        }

        $this->info(sprintf('Deleted %d cached tiles (%.1f MB).', $deleted, $freedBytes / 1048576));

        return self::SUCCESS;
    }
}
