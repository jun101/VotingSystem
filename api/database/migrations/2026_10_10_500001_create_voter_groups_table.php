<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// docs/design/database.md section 2.2: the groups of voters of an election (a class, a section).
// `name_key` is the trimmed, lower-cased name, compared byte for byte (utf8mb4_bin): accents
// count. The unique index is the backstop of the "unique in the election" rule.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voter_groups', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignId('election_id')->constrained('elections')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('name_key', 100)->charset('utf8mb4')->collation('utf8mb4_bin');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(['election_id', 'name_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voter_groups');
    }
};
