<?php

namespace App\Console\Commands;

use App\Enums\PostStatus;
use App\Models\Post;
use Illuminate\Console\Command;

class PublishDuePosts extends Command
{
    protected $signature = 'posts:publish-due';

    protected $description = 'Publish scheduled posts whose publish time has arrived';

    public function handle(): int
    {
        $due = Post::query()
            ->where('status', PostStatus::Scheduled->value)
            ->where('published_at', '<=', now())
            ->get();

        foreach ($due as $post) {
            $post->forceFill(['status' => PostStatus::Published])->save();
        }

        $this->info("Published {$due->count()} post(s).");

        return self::SUCCESS;
    }
}