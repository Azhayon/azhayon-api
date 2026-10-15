<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();

            // Relations
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('category_id')->constrained('categories')->restrictOnDelete();
            $table->foreignId('series_id')->nullable()->constrained('series')->nullOnDelete();

            // Content
            $table->string('title', 200);
            $table->string('subtitle', 255)->nullable();
            $table->string('slug', 220)->unique();
            $table->text('excerpt')->nullable();
            $table->json('content')->nullable(); // TipTap JSON document
            $table->string('subject_title', 200)->nullable(); // the anime/movie/manga this post is about
            $table->string('post_type', 20)->default('news'); // news | review | explainer | list

            // Featured image (dimensions stored so next/image reserves space, no CLS)
            $table->string('featured_image', 2048)->nullable();
            $table->string('featured_image_alt', 255)->nullable();
            $table->unsignedSmallInteger('featured_image_width')->nullable();
            $table->unsignedSmallInteger('featured_image_height')->nullable();

            // SEO
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 320)->nullable();
            $table->string('og_image', 2048)->nullable();
            $table->string('canonical_url', 2048)->nullable(); // optional override
            $table->boolean('noindex')->default(false);

            // Review data (used for Review JSON-LD)
            $table->decimal('review_score', 3, 1)->nullable(); // 0.0 to 10.0

            // Publishing
            $table->string('status', 20)->default('draft'); // draft | scheduled | published
            $table->timestamp('published_at')->nullable();

            // Counters (denormalized for fast listing/sorting)
            $table->unsignedSmallInteger('reading_time_minutes')->default(1);
            $table->unsignedBigInteger('view_count')->default(0);
            $table->decimal('rating_avg', 3, 2)->default(0);
            $table->unsignedInteger('rating_count')->default(0);

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('created_at');
            $table->index(['status', 'published_at']);
            $table->index(['status', 'view_count']);
            $table->fullText(['title', 'subtitle', 'excerpt', 'subject_title']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};