<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// docs/design/database.md section 2.1. No `remember_token`: there is no "remember me".
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('institution_id')->nullable()->constrained('institutions');
            $table->string('role', 20);
            $table->string('name', 150);
            $table->string('email', 255)->unique();
            $table->string('password', 255);
            $table->dateTime('email_verified_at')->nullable();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->dateTime('two_factor_confirmed_at')->nullable();
            $table->char('language', 2)->default('fr');
            $table->dateTime('last_login_at')->nullable();
            $table->dateTime('deleted_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });

        // A platform admin has no institution; every other user has one.
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_institution_matches_role CHECK ((institution_id IS NULL) = (role = 'platform_admin'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
