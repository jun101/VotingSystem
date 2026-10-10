<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// docs/design/database.md section 2.2: the voters of an election. A voter belongs to one
// election. The identifier and the email are unique in the election (the default collation
// ignores case); several rows may hold none (NULL). A group with voters cannot be deleted.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voters', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignId('election_id')->constrained('elections')->cascadeOnDelete();
            $table->foreignId('voter_group_id')->nullable()->constrained('voter_groups')->restrictOnDelete();
            $table->string('full_name', 150);
            $table->string('identifier', 50)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('phone', 30)->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(['election_id', 'identifier']);
            $table->unique(['election_id', 'email']);
            $table->index(['election_id', 'voter_group_id', 'full_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voters');
    }
};
