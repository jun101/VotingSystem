<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Slice 15: the ballot of a second round points to the ballot it was created from
// (docs/api/results/POST-elections-{election}-second-round.md). Unique: one second round per
// ballot, held by the database as well as by the row lock. Cleared when the source goes.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ballots', function (Blueprint $table): void {
            $table->unsignedBigInteger('source_ballot_id')->nullable()->after('result_note');

            $table->foreign('source_ballot_id')->references('id')->on('ballots')->nullOnDelete();
            $table->unique('source_ballot_id');
        });
    }

    public function down(): void
    {
        Schema::table('ballots', function (Blueprint $table): void {
            $table->dropForeign(['source_ballot_id']);
            $table->dropUnique(['source_ballot_id']);
            $table->dropColumn('source_ballot_id');
        });
    }
};
