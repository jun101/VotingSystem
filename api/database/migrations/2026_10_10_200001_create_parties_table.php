<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// docs/design/database.md section 2.2: the parties (slates) of an election. They go with the
// election and the institution. `name_key` is the trimmed, lower-cased name: the unique index
// is the backstop of the "unique in the election" rule.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parties', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignId('election_id')->constrained('elections')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('name_key', 100);
            $table->string('acronym', 15)->nullable();
            $table->char('colour', 7);
            $table->uuid('logo_file')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(['election_id', 'name_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parties');
    }
};
