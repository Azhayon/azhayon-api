<?php

namespace App\Console\Commands;

use App\Support\Images\OrphanFinder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanOrphanImages extends Command
{
    protected $signature = 'images:clean-orphans
        {--force : Actually delete; without it the command only reports}
        {--min-age-days=14 : Never touch images newer than this (minimum 1)}
        {--max=100 : Refuse to delete more than this many image folders in one run}';

    protected $description = 'Remove uploaded image folders that no post or series uses any more';

    public function handle(OrphanFinder $finder): int
    {
        $minAge = max(1, (int) $this->option('min-age-days'));
        $max = max(1, (int) $this->option('max'));

        $orphans = $finder->orphans($minAge);
        $count = count($orphans);

        if ($count === 0) {
            $this->info('No unused images found.');

            return self::SUCCESS;
        }

        $bytes = array_sum(array_column($orphans, 'bytes'));

        $this->info("Found {$count} unused image folder(s), {$this->size($bytes)}.");

        foreach (array_slice(array_keys($orphans), 0, 20) as $directory) {
            $this->line("  {$directory}");
        }

        if ($count > 20) {
            $this->line('  …and '.($count - 20).' more');
        }

        if (! $this->option('force')) {
            $this->comment('Dry run: nothing was deleted. Add --force to delete.');

            return self::SUCCESS;
        }

        if ($count > $max) {
            $this->error("Refusing to delete {$count} folders (limit {$max}). Check the list above, then rerun with a higher --max if it is right.");

            return self::FAILURE;
        }

        foreach (array_keys($orphans) as $directory) {
            Storage::disk('uploads')->deleteDirectory($directory);
        }

        $this->info("Deleted {$count} folder(s), freed {$this->size($bytes)}.");

        return self::SUCCESS;
    }

    private function size(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $value = (float) $bytes;
        $unit = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return round($value, 1).' '.$units[$unit];
    }
}