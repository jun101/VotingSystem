<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// docs/design/database.md section 2.1. Dates are DATETIME, stored in UTC.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('institutions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 150);
            $table->string('type', 20)->default('other');
            $table->uuid('logo_file')->nullable();
            $table->string('description', 500)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('contact_email', 255)->nullable();
            $table->string('timezone', 64)->default('America/Port-au-Prince');
            $table->char('language', 2)->default('fr');
            $table->dateTime('suspended_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('institutions');
    }
};
