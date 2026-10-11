<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// docs/design/database.md section 2.3: the current access code of a voter (FR-CRED-01 to 03), as
// its HMAC only. No uuid and no timestamps: a code is never named by a URL, and `issued_at` is
// the one date. A reissue replaces the hash in the same row.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credentials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignId('election_id')->constrained('elections')->cascadeOnDelete();
            $table->foreignId('voter_id')->unique()->constrained('voters')->cascadeOnDelete();
            $table->binary('code_hash', 32, true);
            $table->dateTime('issued_at');
            $table->foreignId('issued_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('reissue_count')->default(0);
            $table->string('email_status', 10)->nullable();
            $table->dateTime('email_sent_at')->nullable();
            $table->string('email_error', 255)->nullable();

            // A hash is unique in its election: a collision draws a new code.
            $table->unique(['election_id', 'code_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credentials');
    }
};
