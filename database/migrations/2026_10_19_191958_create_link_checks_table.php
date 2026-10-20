<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('link_checks', function (Blueprint $table) {
            $table->id();
            $table->char('url_hash', 64)->unique(); // sha256 of the normalised URL
            $table->string('url', 2048);
            $table->string('status', 20)->default('pending'); // pending | ok | moved | failing | broken | unverifiable
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('final_url', 2048)->nullable();
            $table->string('note', 100)->nullable();
            $table->unsignedTinyInteger('failures')->default(0);
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('next_check_at')->nullable()->index();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('link_checks');
    }
};