<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneExpiredCache extends Command
{
    protected $signature = 'cache:prune-expired';

    protected $description = 'Delete expired rows from the database cache table';

    public function handle(): int
    {
        $deleted = DB::connection(config('cache.stores.database.connection'))
            ->table(config('cache.stores.database.table', 'cache'))
            ->where('expiration', '<=', now()->getTimestamp())
            ->delete();

        $this->info("Removed {$deleted} expired cache row(s).");

        return self::SUCCESS;
    }
}