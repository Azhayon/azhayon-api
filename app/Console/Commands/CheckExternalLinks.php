<?php

namespace App\Console\Commands;

use App\Models\LinkCheck;
use App\Support\Links\LinkProber;
use Illuminate\Console\Command;

class CheckExternalLinks extends Command
{
    protected $signature = 'links:check-external
        {--limit=60 : Most links to check in one run}
        {--budget=45 : Seconds after which no new checks are started}';

    protected $description = 'Check outside links used in published posts, politely and a few at a time';

    private const PER_HOST = 3;

    public function handle(LinkProber $prober): int
    {
        $started = microtime(true);
        $limit = max(1, (int) $this->option('limit'));
        $budget = max(1, (int) $this->option('budget'));
        $delay = max(0, (int) config('azhayon.link_checker.delay_ms'));

        $due = LinkCheck::query()
            ->where(fn ($query) => $query->whereNull('next_check_at')->orWhere('next_check_at', '<=', now()))
            ->orderBy('next_check_at')
            ->orderBy('id')
            ->limit($limit * 5) // extra candidates, because the per-site cap may skip some
            ->get();

        $perHost = [];
        $checked = 0;

        foreach ($due as $check) {
            if ($checked >= $limit || microtime(true) - $started > $budget) {
                break;
            }

            $host = (string) parse_url($check->url, PHP_URL_HOST);

            if (($perHost[$host] ?? 0) >= self::PER_HOST) {
                continue; // left for a later run
            }

            $perHost[$host] = ($perHost[$host] ?? 0) + 1;

            $this->apply($check, $prober->probe($check->url));
            $checked++;

            if ($delay > 0) {
                usleep($delay * 1000);
            }
        }

        $this->info("Checked {$checked} link(s).");

        return self::SUCCESS;
    }

    /** @param  array{outcome: string, http_status: ?int, final_url: ?string, note: ?string}  $result */
    private function apply(LinkCheck $check, array $result): void
    {
        $now = now();
        $outcome = $result['outcome'];

        $attributes = [
            'http_status' => $result['http_status'],
            'final_url' => $outcome === 'moved' ? $result['final_url'] : null,
            'note' => $result['note'],
            'checked_at' => $now,
        ];

        if ($outcome === 'failed') {
            // One failure is only "failing": a second one, a day later, confirms it.
            $failures = min(255, $check->failures + 1);
            $confirmed = $failures >= 2;

            $attributes += [
                'failures' => $failures,
                'status' => $confirmed ? LinkCheck::BROKEN : LinkCheck::FAILING,
                'next_check_at' => $now->copy()->addDays($confirmed ? 3 : 1),
            ];
        } else {
            $attributes += [
                'failures' => 0,
                'status' => match ($outcome) {
                    'moved' => LinkCheck::MOVED,
                    'unverifiable' => LinkCheck::UNVERIFIABLE,
                    default => LinkCheck::OK,
                },
                'next_check_at' => $now->copy()->addDays($outcome === 'unverifiable' ? 30 : 14),
            ];
        }

        $check->forceFill($attributes)->save();
    }
}