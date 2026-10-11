<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// docs/design/database.md section 2.2: the groups a group ballot covers (FR-BAL-03). No id, no
// uuid, no timestamps. The rows go with the ballot; a group a ballot covers cannot be deleted.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ballot_voter_group', function (Blueprint $table): void {
            $table->foreignId('ballot_id')->constrained('ballots')->cascadeOnDelete();
            $table->foreignId('voter_group_id')->constrained('voter_groups')->restrictOnDelete();

            $table->primary(['ballot_id', 'voter_group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ballot_voter_group');
    }
};
