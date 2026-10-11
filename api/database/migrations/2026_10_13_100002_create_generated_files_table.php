<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// docs/design/database.md section 2.3: the slip PDFs (and later the code export files) of an
// election. The file itself is on the private `credentials` disk, encrypted, under `stored_file`.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generated_files', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignId('election_id')->constrained('elections')->cascadeOnDelete();
            // Who asked; the file outlives the user.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 15);
            // The group of the slips; null for the voters with no group, and when the group is deleted.
            $table->foreignId('voter_group_id')->nullable()->constrained('voter_groups')->nullOnDelete();
            $table->unsignedSmallInteger('part')->default(1);
            $table->unsignedSmallInteger('parts')->default(1);
            $table->unsignedInteger('voter_count');
            $table->string('status', 10);
            $table->string('failure', 30)->nullable();
            $table->uuid('stored_file')->nullable();
            $table->unsignedInteger('size_bytes')->nullable();
            $table->dateTime('expires_at');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->index(['election_id', 'status']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generated_files');
    }
};
