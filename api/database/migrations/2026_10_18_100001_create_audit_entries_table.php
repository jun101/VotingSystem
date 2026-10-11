<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The audit log (docs/design/database.md section 2.5, docs/slices/17-audit-log.md). Append only: the `app`
 * account has INSERT and SELECT on this table and nothing else (`db:grant-app`, `database.append_only_tables`),
 * so the application can neither change nor remove an entry.
 *
 * `institution_id` is null for an action of the platform. `ip_address` is null for what the scheduler does.
 * An election or a user that is removed leaves the entry in place: the actor's name and the subject's uuid
 * are copied into it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_entries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('institution_id')->nullable()->constrained('institutions')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name', 150);
            $table->string('action', 60);
            $table->foreignId('election_id')->nullable()->constrained('elections')->nullOnDelete();
            $table->string('subject_type', 40)->nullable();
            $table->uuid('subject_uuid')->nullable();
            $table->json('details')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->dateTime('created_at');

            $table->index(['institution_id', 'created_at']);
            $table->index(['institution_id', 'election_id']);
            $table->index(['institution_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_entries');
    }
};
