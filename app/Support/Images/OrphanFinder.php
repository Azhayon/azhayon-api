<?php

namespace App\Support\Images;

use App\Models\Post;
use App\Models\Series;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

class OrphanFinder
{
    private const DIRECTORY_PATTERN = '#^\d{4}/\d{2}/[a-z0-9]{12}$#';

    private const REFERENCE_PATTERN = '#^(\d{4}/\d{2}/[a-z0-9]{12})/\d{2,5}\.webp$#';

    /**
     * Image folders still referenced anywhere. Any string in a post's content that
     * looks like one of our image paths counts, so future block types stay safe.
     *
     * @return array<string, true>
     */
    public function referencedDirectories(): array
    {
        $found = [];

        $add = function (mixed $value) use (&$found): void {
            if (is_string($value) && preg_match(self::REFERENCE_PATTERN, $value, $matches)) {
                $found[$matches[1]] = true;
            }
        };

        // Soft-deleted posts count: restoring one must not find its images gone.
        Post::withTrashed()
            ->select(['id', 'featured_image', 'og_image', 'content'])
            ->chunkById(100, function ($posts) use ($add): void {
                foreach ($posts as $post) {
                    $add($post->featured_image);
                    $add($post->og_image);

                    $content = $post->content; // a local copy: PHP cannot pass a model property by reference

                    if (is_array($content)) {
                        array_walk_recursive($content, fn ($value) => $add($value));
                    }
                }
            });

        Series::query()
            ->select(['id', 'cover_image'])
            ->chunkById(100, function ($all) use ($add): void {
                foreach ($all as $series) {
                    $add($series->cover_image);
                }
            });

        return $found;
    }

    /**
     * Every image folder on disk that matches our naming, with its size and
     * the time its newest file was written.
     *
     * @return array<string, array{bytes: int, modified: Carbon}>
     */
    public function storedDirectories(): array
    {
        $disk = Storage::disk('uploads');
        $found = [];

        foreach ($disk->directories('') as $year) {
            if (! preg_match('#^\d{4}$#', $year)) {
                continue;
            }

            foreach ($disk->directories($year) as $month) {
                if (! preg_match('#^\d{4}/\d{2}$#', $month)) {
                    continue;
                }

                foreach ($disk->directories($month) as $directory) {
                    if (! preg_match(self::DIRECTORY_PATTERN, $directory)) {
                        continue;
                    }

                    $files = $disk->files($directory);

                    if ($files === []) {
                        continue;
                    }

                    $found[$directory] = [
                        'bytes' => array_sum(array_map(fn (string $file) => $disk->size($file), $files)),
                        'modified' => Carbon::createFromTimestamp(
                            max(array_map(fn (string $file) => $disk->lastModified($file), $files))
                        ),
                    ];
                }
            }
        }

        return $found;
    }

    /**
     * Unreferenced folders whose newest file is at least $minAgeDays old.
     *
     * @return array<string, array{bytes: int, modified: Carbon}>
     */
    public function orphans(int $minAgeDays): array
    {
        // References first, then the disk: anything uploaded in between is too new to qualify.
        $referenced = $this->referencedDirectories();
        $cutoff = now()->subDays(max(0, $minAgeDays));

        return array_filter(
            $this->storedDirectories(),
            fn (array $info, string $directory): bool => ! isset($referenced[$directory]) && $info['modified']->lt($cutoff),
            ARRAY_FILTER_USE_BOTH,
        );
    }
}