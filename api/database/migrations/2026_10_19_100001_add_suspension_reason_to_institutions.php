<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Slice 18: the reason the platform admin gave when suspending an institution
// (docs/api/platform/POST-platform-institutions-{institution}-suspend.md). Cleared at reactivation.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('institutions', 'suspension_reason')) {
            return;
        }

        Schema::table('institutions', function (Blueprint $table): void {
            $table->string('suspension_reason', 500)->nullable()->after('suspended_at');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('institutions', 'suspension_reason')) {
            Schema::table('institutions', function (Blueprint $table): void {
                $table->dropColumn('suspension_reason');
            });
        }
    }
};
