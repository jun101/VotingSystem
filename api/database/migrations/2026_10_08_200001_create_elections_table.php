<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// docs/design/database.md section 2.2: every column of `elections`, the later slices fill the
// unused ones. Dates are UTC. The link to a first round is cleared when that election goes.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('elections', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->unsignedBigInteger('parent_election_id')->nullable();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('timezone', 64);
            $table->char('language', 2);
            $table->string('status', 15)->default('draft');
            $table->uuid('cover_file')->nullable();
            $table->string('candidate_order', 10)->default('manual');
            $table->string('results_display', 10)->default('full');
            $table->dateTime('opened_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->dateTime('archived_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->foreign('parent_election_id')->references('id')->on('elections')->nullOnDelete();
            $table->index(['institution_id', 'status']);
            $table->index(['status', 'starts_at']);
            $table->index(['status', 'ends_at']);
        });

        DB::statement('ALTER TABLE elections ADD CONSTRAINT elections_ends_after_starts CHECK (ends_at > starts_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('elections');
    }
};
