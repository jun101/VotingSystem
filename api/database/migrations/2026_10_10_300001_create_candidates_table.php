<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// docs/design/database.md section 2.2: the candidates of a ballot. They go with the ballot, the
// election and the institution; deleting a party only clears `party_id`.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidates', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignId('election_id')->constrained('elections')->cascadeOnDelete();
            $table->foreignId('ballot_id')->constrained('ballots')->cascadeOnDelete();
            $table->foreignId('party_id')->nullable()->constrained('parties')->nullOnDelete();
            $table->string('first_name', 80);
            $table->string('last_name', 80);
            $table->string('sex', 6);
            $table->string('slogan', 160)->nullable();
            $table->string('biography', 1000)->nullable();
            $table->uuid('photo_file')->nullable();
            $table->unsignedSmallInteger('position');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->index(['ballot_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidates');
    }
};
