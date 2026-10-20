<?php

namespace Tests\Feature\Images;

use App\Models\Category;
use App\Models\Post;
use App\Models\Series;
use App\Models\User;
use App\Support\Images\ImageProcessor;
use App\Support\Images\OrphanFinder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OrphanImagesTest extends TestCase
{
    use RefreshDatabase;

    /** @var \Illuminate\Filesystem\FilesystemAdapter */
    private $disk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disk = Storage::fake('uploads');
    }

    /** Stores a real image and backdates its files. Returns the largest variant's path. */
    private function makeImage(int $ageDays = 30): string
    {
        $image = imagecreatetruecolor(800, 400);
        ob_start();
        imagejpeg($image);
        $path = app(ImageProcessor::class)->store((string) ob_get_clean());

        foreach ($this->disk->files(dirname($path)) as $file) {
            touch($this->disk->path($file), time() - $ageDays * 86400);
        }

        return $path;
    }

    private function orphanDirectories(int $minAgeDays = 14): array
    {
        return array_keys(app(OrphanFinder::class)->orphans($minAgeDays));
    }

    public function test_only_old_unreferenced_images_are_orphans(): void
    {
        $kept = $this->makeImage();
        $unused = $this->makeImage();
        $this->makeImage(1); // too recent to touch

        Series::create(['title' => 'S', 'slug' => 's', 'cover_image' => $kept]);

        $this->assertSame([dirname($unused)], $this->orphanDirectories());
    }

    public function test_references_are_found_everywhere(): void
    {
        $images = [];

        foreach (range(1, 7) as $number) {
            $images[$number] = $this->makeImage();
        }

        $author = User::factory()->create(['role' => 'admin', 'provider_name' => null, 'provider_id' => null]);
        $category = Category::create(['name' => 'Anime', 'slug' => 'anime']);

        Post::factory()->create([
            'author_id' => $author->id,
            'category_id' => $category->id,
            'featured_image' => $images[1],
            'og_image' => $images[2],
            'content' => ['type' => 'doc', 'content' => [
                ['type' => 'customBlock', 'attrs' => ['id' => 'image', 'config' => ['image' => $images[3], 'alt' => 'a']]],
                ['type' => 'customBlock', 'attrs' => ['id' => 'spoiler', 'config' => ['text' => 't', 'image' => $images[4], 'image_alt' => 'x']]],
            ]],
        ]);

        $deleted = Post::factory()->create([
            'author_id' => $author->id,
            'category_id' => $category->id,
            'featured_image' => $images[5],
        ]);
        $deleted->delete(); // soft delete: it could still be restored

        Series::create(['title' => 'S', 'slug' => 's', 'cover_image' => $images[6]]);

        $this->assertSame([dirname($images[7])], $this->orphanDirectories());
    }

    public function test_unknown_files_and_folders_are_never_touched(): void
    {
        $disk = $this->disk;

        $strays = [
            'notes/readme.txt',
            '.htaccess',
            '2026/10/short/1600.webp',
            '2026/10/ABCDEFGHIJKL/1600.webp',
            '2026/1x/abcdefghijkl/1600.webp',
        ];

        foreach ($strays as $file) {
            $disk->put($file, 'x');
            touch($disk->path($file), time() - 90 * 86400);
        }

        $this->assertSame([], $this->orphanDirectories());

        $this->artisan('images:clean-orphans', ['--force' => true])->assertSuccessful();

        foreach ($strays as $file) {
            $disk->assertExists($file);
        }
    }

    public function test_the_command_only_reports_unless_forced(): void
    {
        $unused = $this->makeImage();
        $kept = $this->makeImage();
        Series::create(['title' => 'S', 'slug' => 's', 'cover_image' => $kept]);

        $this->artisan('images:clean-orphans')->expectsOutputToContain('Dry run')->assertSuccessful();
        $this->disk->assertExists($unused);

        $this->artisan('images:clean-orphans', ['--force' => true])->assertSuccessful();

        $this->disk->assertMissing($unused);
        $this->disk->assertExists($kept);
    }

    public function test_the_command_refuses_to_delete_more_than_the_limit(): void
    {
        $images = [$this->makeImage(), $this->makeImage(), $this->makeImage()];

        $this->artisan('images:clean-orphans', ['--force' => true, '--max' => 2])->assertFailed();

        foreach ($images as $image) {
            $this->disk->assertExists($image);
        }
    }

    public function test_recent_images_are_protected_by_the_age_limit(): void
    {
        $recent = $this->makeImage(2);

        $this->artisan('images:clean-orphans', ['--force' => true])->assertSuccessful();
        $this->disk->assertExists($recent);

        $this->artisan('images:clean-orphans', ['--force' => true, '--min-age-days' => 1])->assertSuccessful();
        $this->disk->assertMissing($recent);
    }
}