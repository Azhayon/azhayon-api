<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_views_daily', function (Blueprint $table) {
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('views')->default(0);

            $table->primary(['post_id', 'date']);
            $table->index('date'); // fast "last 48 hours" sums for trending
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_views_daily');
    }
};