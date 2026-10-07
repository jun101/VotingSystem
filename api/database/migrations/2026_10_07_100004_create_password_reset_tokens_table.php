<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// docs/design/database.md section 2.1: the link sent to reset a password. Only the SHA-256 hash of the
// token is stored. One live token per user (unique `user_id`): a new one replaces the old.
// No uuid, no updated_at.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->binary('token_hash', length: 32, fixed: true)->unique();
            $table->dateTime('expires_at');
            $table->dateTime('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
    }
};
