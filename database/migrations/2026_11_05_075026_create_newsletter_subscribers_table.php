<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('newsletter_subscribers', function (Blueprint $table) {
            $table->id();
            $table->string('email', 254)->unique();
            $table->string('status', 16)->default('pending')->index();
            $table->char('confirm_token_hash', 64)->nullable()->unique();
            $table->timestamp('confirm_expires_at')->nullable();
            $table->char('unsubscribe_token', 40)->unique();
            $table->timestamp('last_confirmation_sent_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('newsletter_subscribers');
    }
};