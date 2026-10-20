<?php

namespace App\Console\Commands;

use App\Support\Links\LinkRegistry;
use Illuminate\Console\Command;

class IndexExternalLinks extends Command
{
    protected $signature = 'links:index';

    protected $description = 'Rescan published posts for outside links and drop links no longer used';

    public function handle(LinkRegistry $registry): int
    {
        $result = $registry->reindexAll();

        $this->info("Registered {$result['links']} link(s); removed {$result['pruned']} unused link(s).");

        return self::SUCCESS;
    }
}