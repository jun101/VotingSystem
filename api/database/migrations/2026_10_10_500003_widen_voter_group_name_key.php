<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// The key is the lower-cased name, and lower-casing can make a name longer (a 100-character name
// holding "İ" gives 101 characters). The key is wider than the name so that it always fits.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE voter_groups MODIFY name_key VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE voter_groups MODIFY name_key VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL');
    }
};
