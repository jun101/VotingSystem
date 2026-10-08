<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// docs/design/database.md section 2.1: the invitations of the institution's users. Only the SHA-256
// hash of the token is stored. No `updated_at`. No unique key on (institution, email): an accepted
// invitation stays as a record, and the same address can be invited again after a removal; the
// code keeps one unaccepted invitation per address and institution.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignId('invited_by_user_id')->constrained('users');
            $table->string('email', 255);
            $table->string('role', 20);
            $table->binary('token_hash', length: 32, fixed: true)->unique();
            $table->dateTime('expires_at');
            $table->dateTime('accepted_at')->nullable();
            $table->dateTime('created_at');

            $table->index(['institution_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
