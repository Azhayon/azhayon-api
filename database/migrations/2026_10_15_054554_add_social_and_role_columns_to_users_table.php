<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Social users may have no email, and only admins/editors use passwords.
            $table->string('email')->nullable()->change();
            $table->string('password')->nullable()->change();

            $table->string('avatar_url', 2048)->nullable()->after('password');
            $table->string('role', 20)->default('user')->after('avatar_url');
            $table->string('provider_name', 30)->nullable()->after('role');
            $table->string('provider_id')->nullable()->after('provider_name');
            $table->timestamp('banned_at')->nullable()->after('provider_id');
            $table->softDeletes();

            $table->unique(['provider_name', 'provider_id']);
            $table->index('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['provider_name', 'provider_id']);
            $table->dropIndex(['role']);
            $table->dropSoftDeletes();
            $table->dropColumn([
                'avatar_url',
                'role',
                'provider_name',
                'provider_id',
                'banned_at',
            ]);

            $table->string('email')->nullable(false)->change();
            $table->string('password')->nullable(false)->change();
        });
    }
};