<?php

namespace Database\Seeders;

use App\Enums\TagType;
use App\Models\Category;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Rating;
use App\Models\Series;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fake data for local development only.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('DemoDataSeeder must not run in production.');

            return;
        }

        $categories = Category::all();

        if ($categories->isEmpty()) {
            $this->command?->error('Run BaseDataSeeder first.');

            return;
        }

        $authors = User::factory()->count(3)->editor()->create();
        $readers = User::factory()->count(25)->create();
        $tags = $this->seedTags();
        $series = $this->seedSeries();

        $relations = fn () => [
            'author_id' => $authors->random()->id,
            'category_id' => $categories->random()->id,
            'series_id' => fake()->boolean(35) ? $series->random()->id : null,
        ];

        $published = Post::factory()->count(32)->state($relations)->create();
        $drafts = Post::factory()->count(5)->draft()->state($relations)->create();
        $scheduled = Post::factory()->count(3)->scheduled()->state($relations)->create();

        // Tags on every post
        foreach ($published->concat($drafts)->concat($scheduled) as $post) {
            $post->tags()->attach(
                $tags->random(fake()->numberBetween(2, 5))->pluck('id')->all()
            );
        }

        // Seven days of view history for published posts (feeds "trending")
        $viewRows = [];
        foreach ($published as $post) {
            foreach (range(0, 6) as $daysAgo) {
                $viewRows[] = [
                    'post_id' => $post->id,
                    'date' => now()->subDays($daysAgo)->toDateString(),
                    'views' => fake()->numberBetween(5, 400),
                ];
            }
        }
        DB::table('post_views_daily')->insert($viewRows);

        foreach ($published as $post) {
            $this->seedComments($post, $readers);
            $this->seedRatings($post, $readers);
        }

        $this->command?->info('Demo data created.');
    }

    private function seedTags(): Collection
    {
        $definitions = [
            TagType::Studio->value => ['MAPPA', 'Studio Ghibli', 'Ufotable', 'Wit Studio', 'Bones', 'A-1 Pictures'],
            TagType::Genre->value => ['Isekai', 'Shonen', 'Seinen', 'Romance', 'Horror', 'Action'],
            TagType::General->value => ['Spoilers', 'Trailer', 'Season Review', 'Lore', 'Release Date'],
        ];

        $tags = collect();

        foreach ($definitions as $type => $names) {
            foreach ($names as $name) {
                $tags->push(Tag::firstOrCreate(
                    ['slug' => Str::slug($name)],
                    ['name' => $name, 'type' => $type],
                ));
            }
        }

        return $tags;
    }

    private function seedSeries(): Collection
    {
        $series = collect();

        foreach (['Jujutsu Kaisen', 'Solo Leveling', 'Attack on Titan'] as $title) {
            $series->push(Series::firstOrCreate(
                ['slug' => Str::slug($title)],
                ['title' => $title, 'description' => fake()->paragraph()],
            ));
        }

        return $series;
    }

    private function seedComments(Post $post, Collection $readers): void
    {
        $topLevel = Comment::factory()
            ->count(fake()->numberBetween(1, 6))
            ->state(fn () => [
                'post_id' => $post->id,
                'user_id' => $readers->random()->id,
            ])
            ->create();

        foreach ($topLevel as $comment) {
            if (fake()->boolean(30)) {
                Comment::factory()->state([
                    'post_id' => $post->id,
                    'user_id' => $readers->random()->id,
                    'parent_id' => $comment->id,
                ])->create();
            }
        }
    }

    private function seedRatings(Post $post, Collection $readers): void
    {
        $scores = [];

        foreach ($readers->random(fake()->numberBetween(3, 12)) as $reader) {
            $score = fake()->randomElement([3, 4, 4, 5, 5, 5, 2, 1]);

            Rating::create([
                'post_id' => $post->id,
                'user_id' => $reader->id,
                'score' => $score,
            ]);

            $scores[] = $score;
        }

        // Counters are system-managed (not fillable), so update via the query builder.
        Post::whereKey($post->id)->update([
            'rating_avg' => round(array_sum($scores) / count($scores), 2),
            'rating_count' => count($scores),
        ]);
    }
}