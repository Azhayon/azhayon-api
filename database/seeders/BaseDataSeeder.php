<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Setting;
use App\Models\User;
use App\Support\Settings\AdSettings;
use Illuminate\Database\Seeder;

/**
 * Real starting data. Safe to run in production and safe to re-run.
 */
class BaseDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedCategories();
        $this->seedSettings();
        $this->seedAdmin();
    }

    private function seedCategories(): void
    {
        $categories = [
            ['Anime', 'anime', 'News, reviews and guides for anime series and films.'],
            ['Movies', 'movies', 'Movie news, reviews, trailers and explainers.'],
            ['Manhwa', 'manhwa', 'Korean comics: chapter news, reviews and recommendations.'],
            ['Manga', 'manga', 'Japanese comics: chapter news, reviews and recommendations.'],
        ];

        foreach ($categories as [$name, $slug, $description]) {
            Category::firstOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'description' => $description],
            );
        }
    }

    private function seedSettings(): void
    {
        // Created only if missing, so reseeding never overwrites admin changes.
        $defaults = [
            AdSettings::KEY => AdSettings::defaults(),
            'site' => [
                'name' => 'Azhayon Media',
                'tagline' => 'Movies, anime, manhwa and manga: news, reviews and guides.',
            ],
        ];

        foreach ($defaults as $key => $value) {
            Setting::firstOrCreate(['key' => $key], ['value' => $value]);
        }
    }

    private function seedAdmin(): void
    {
        $email = config('azhayon.admin.email');
        $password = config('azhayon.admin.password');

        if (! $email || ! $password) {
            $this->command?->warn('ADMIN_EMAIL / ADMIN_PASSWORD not set: skipping admin account.');

            return;
        }

        if (strlen($password) < 12) {
            $this->command?->warn('ADMIN_PASSWORD must be at least 12 characters: skipping admin account.');

            return;
        }

        $admin = User::withTrashed()->firstOrNew(['email' => $email]);

        if ($admin->exists) {
            $this->command?->info('Admin account already exists: left unchanged.');

            return;
        }

        $admin->forceFill([
            'name' => config('azhayon.admin.name'),
            'password' => $password, // hashed by the model cast
            'role' => UserRole::Admin,
            'email_verified_at' => now(),
        ])->save();

        $this->command?->info('Admin account created.');
    }
}