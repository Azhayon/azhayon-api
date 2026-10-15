<?php

namespace Database\Factories;

use App\Enums\PostStatus;
use App\Enums\PostType;
use App\Models\Post;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * author_id and category_id must be supplied by the caller.
 *
 * @extends Factory<Post>
 */
class PostFactory extends Factory
{
    protected $model = Post::class;

    private const SUBJECTS = [
        'Jujutsu Kaisen',
        'Solo Leveling',
        'Attack on Titan',
        'Demon Slayer',
        'Chainsaw Man',
        'Frieren: Beyond Journey\'s End',
        'One Piece',
        'Blue Lock',
    ];

    public function definition(): array
    {
        $title = Str::title(fake()->unique()->words(fake()->numberBetween(3, 7), true));
        $type = fake()->randomElement(PostType::cases());
        [$content, $wordCount] = $this->document();

        return [
            'title' => $title,
            'subtitle' => fake()->boolean(60) ? fake()->sentence(8) : null,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
            'excerpt' => fake()->sentences(2, true),
            'content' => $content,
            'subject_title' => fake()->randomElement(self::SUBJECTS),
            'post_type' => $type,
            'review_score' => $type === PostType::Review ? fake()->randomFloat(1, 5, 9.9) : null,
            'status' => PostStatus::Published,
            'published_at' => fake()->dateTimeBetween('-60 days', '-1 hour'),
            'reading_time_minutes' => max(1, (int) ceil($wordCount / 200)),
            'view_count' => fake()->numberBetween(0, 5000),
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => [
            'status' => PostStatus::Draft,
            'published_at' => null,
            'view_count' => 0,
        ]);
    }

    public function scheduled(): static
    {
        return $this->state(fn () => [
            'status' => PostStatus::Scheduled,
            'published_at' => fake()->dateTimeBetween('+1 day', '+14 days'),
            'view_count' => 0,
        ]);
    }

    /**
     * Builds a TipTap-shaped document: an intro paragraph, then 3-4 sections
     * (h2 plus 2-3 paragraphs each). Returns [document, wordCount].
     */
    private function document(): array
    {
        $content = [];
        $words = 0;

        $paragraph = function () use (&$words): array {
            $text = fake()->paragraph(fake()->numberBetween(3, 6));
            $words += str_word_count($text);

            return [
                'type' => 'paragraph',
                'content' => [['type' => 'text', 'text' => $text]],
            ];
        };

        $content[] = $paragraph();

        foreach (range(1, fake()->numberBetween(3, 4)) as $section) {
            $content[] = [
                'type' => 'heading',
                'attrs' => ['level' => 2],
                'content' => [[
                    'type' => 'text',
                    'text' => Str::title(fake()->words(fake()->numberBetween(2, 5), true)),
                ]],
            ];

            foreach (range(1, fake()->numberBetween(2, 3)) as $p) {
                $content[] = $paragraph();
            }
        }

        return [['type' => 'doc', 'content' => $content], $words];
    }
}