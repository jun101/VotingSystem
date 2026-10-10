<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// The key is already trimmed and lower-cased by PHP (Party::setNameAttribute). With the default
// accent-insensitive collation "Unite" and "Unité" collided. A binary collation makes the unique
// index (election_id, name_key) compare the exact characters: accents count.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE parties MODIFY name_key VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE parties MODIFY name_key VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');
    }
};
