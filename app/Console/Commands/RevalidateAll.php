<?php

namespace App\Console\Commands;

use App\Support\Revalidation\PendingRevalidation;
use Illuminate\Console\Command;

class RevalidateAll extends Command
{
    protected $signature = 'revalidate:all';

    protected $description = 'Mark every cached frontend page as out of date (run after each deploy)';

    public function handle(): int
    {
        if (! PendingRevalidation::configured()) {
            $this->comment('Revalidation is not configured, so there is nothing to do.');

            return self::SUCCESS;
        }

        PendingRevalidation::add(['all']);

        return $this->call('revalidate:flush');
    }
}