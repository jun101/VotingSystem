<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// docs/design/database.md section 2.3: a voter file uploaded to an election, checked in the
// background and imported once confirmed. One import runs at a time per election. The file sits
// on the private `imports` disk under `stored_file`, never under its original name. The rows are
// the checked rows read by the preview and by the error file; they go with the import.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voter_imports', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignId('election_id')->constrained('elections')->cascadeOnDelete();
            // Who uploaded; the import outlives the user.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('original_name', 255);
            $table->uuid('stored_file');
            $table->json('columns')->nullable();
            $table->json('mapping')->nullable();
            $table->string('status', 15);
            $table->string('failure', 30)->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('invalid_rows')->default(0);
            $table->unsignedInteger('duplicate_rows')->default(0);
            $table->json('new_groups')->nullable();
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('imported_rows')->default(0);
            $table->dateTime('finished_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->index(['election_id', 'status']);
            $table->index('created_at');
        });

        Schema::create('voter_import_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignId('voter_import_id')->constrained('voter_imports')->cascadeOnDelete();
            // The line in the file; the header is line 1.
            $table->unsignedInteger('line');
            $table->string('status', 10);
            $table->json('reasons');
            $table->text('full_name')->nullable();
            $table->text('group_name')->nullable();
            $table->text('identifier')->nullable();
            $table->text('email')->nullable();
            $table->text('phone')->nullable();
            // The original cell text of the five fields, for the error file.
            $table->json('raw')->nullable();

            $table->unique(['voter_import_id', 'line']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voter_import_rows');
        Schema::dropIfExists('voter_imports');
    }
};
