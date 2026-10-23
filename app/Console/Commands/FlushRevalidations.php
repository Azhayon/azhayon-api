<?php

namespace App\Console\Commands;

use App\Support\Revalidation\RevalidationSender;
use Illuminate\Console\Command;

class FlushRevalidations extends Command
{
    protected $signature = 'revalidate:flush';

    protected $description = 'Tell the frontend which cached pages are out of date';

    public function handle(RevalidationSender $sender): int
    {
        $result = $sender->flush();

        match ($result['status']) {
            'not-configured' => $this->comment('Revalidation is not configured (REVALIDATE_SECRET is missing or too short).'),
            'nothing' => $this->info('Nothing to send.'),
            'sent' => $this->info("Sent {$result['tags']} tag(s) to the frontend."),
            'failed' => $this->error('Could not deliver to the frontend. The tags stay queued and will be retried.'),
        };

        return $result['status'] === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}