<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// docs/design/database.md section 2.3 (slice 11, review round).
// `generated_files.run`: one UUID shared by the files of a run (and its own for a reissue), so the
// file of codes finds the slips of its run without relying on a timestamp. `ungrouped`: the file of
// codes was asked for the scope `ungrouped`, so its name says so.
// `credentials.email_hash`: the keyed hash of the address the run was to email the code to (the job
// refuses an address that changed); `email_file_id`: the slip file whose phase 1 queued the message,
// so a file that crashed can fail the messages it had queued. Both are cleared when no message is queued.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generated_files', function (Blueprint $table): void {
            $table->char('run', 36)->nullable()->after('uuid');
            $table->boolean('ungrouped')->default(false)->after('replace');
            $table->index(['election_id', 'run']);
        });

        Schema::table('credentials', function (Blueprint $table): void {
            $table->binary('email_hash', 32, true)->nullable()->after('email_error');
            $table->foreignId('email_file_id')->nullable()->after('email_hash')->constrained('generated_files')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('credentials', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('email_file_id');
            $table->dropColumn('email_hash');
        });

        Schema::table('generated_files', function (Blueprint $table): void {
            $table->dropIndex(['election_id', 'run']);
            $table->dropColumn(['run', 'ungrouped']);
        });
    }
};
