<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The secrecy boundary (docs/design/database.md section 2.4). These four tables have no
 * timestamp and no auto-increment key: either would line a vote up with the participation
 * written in the same transaction. Nothing is added to them without a new decision.
 *
 * Every reference restricts deletion: an election with votes, a voter who voted and a
 * candidate that was chosen cannot be deleted. The `app` account has INSERT and SELECT only
 * on these tables (`db:grant-app`, `database.append_only_tables`).
 */
return new class extends Migration
{
    public function up(): void
    {
        // "This voter has voted". The unique key is the clustered key: rows are kept in voter order, not in time order.
        Schema::create('participations', function (Blueprint $table): void {
            $table->foreignId('election_id')->constrained('elections')->restrictOnDelete();
            $table->foreignId('voter_id')->constrained('voters')->restrictOnDelete();
            $table->dateTime('voted_at');

            $table->unique(['election_id', 'voter_id']);
            $table->index(['election_id', 'voted_at']);
        });

        // One anonymous ballot paper: a random uuid, its election and its ballot.
        Schema::create('votes', function (Blueprint $table): void {
            $table->uuid('uuid')->primary();
            $table->foreignId('election_id')->constrained('elections')->restrictOnDelete();
            $table->foreignId('ballot_id')->constrained('ballots')->restrictOnDelete();
        });

        // The candidates ticked on a paper. No row for a paper is a blank vote.
        Schema::create('vote_choices', function (Blueprint $table): void {
            $table->uuid('vote_uuid');
            $table->foreignId('candidate_id')->constrained('candidates')->restrictOnDelete();

            $table->primary(['vote_uuid', 'candidate_id']);
            $table->foreign('vote_uuid')->references('uuid')->on('votes')->restrictOnDelete();
        });

        // The sealed link between a vote and its voter: readable only with the private key.
        Schema::create('vote_audit', function (Blueprint $table): void {
            $table->uuid('uuid')->primary();
            $table->foreignId('election_id')->constrained('elections')->restrictOnDelete();
            $table->binary('payload', 255);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vote_audit');
        Schema::dropIfExists('vote_choices');
        Schema::dropIfExists('votes');
        Schema::dropIfExists('participations');
    }
};
