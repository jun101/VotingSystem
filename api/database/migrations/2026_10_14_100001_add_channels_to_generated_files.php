<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// docs/design/database.md section 2.3 (slice 11): what the job of a file must do besides the slips.
// `send_email`: queue an email for each voter served who has an address. `replace`: the run is
// the scope `failed_email`, so the voters served already have a code that is replaced.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generated_files', function (Blueprint $table): void {
            $table->boolean('send_email')->default(false)->after('failure');
            $table->boolean('replace')->default(false)->after('send_email');
        });
    }

    public function down(): void
    {
        Schema::table('generated_files', function (Blueprint $table): void {
            $table->dropColumn(['send_email', 'replace']);
        });
    }
};
