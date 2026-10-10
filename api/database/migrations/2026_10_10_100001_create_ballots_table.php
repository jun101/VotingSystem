<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// docs/design/database.md section 2.2: the positions of an election. They go with the election
// and the institution.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ballots', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignId('election_id')->constrained('elections')->cascadeOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('position');
            $table->unsignedTinyInteger('seats')->default(1);
            $table->boolean('allow_blank')->default(true);
            $table->string('scope', 10)->default('general');
            $table->text('result_note')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->index(['election_id', 'position']);
        });

        DB::statement('ALTER TABLE ballots ADD CONSTRAINT ballots_seats_at_least_one CHECK (seats >= 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('ballots');
    }
};
