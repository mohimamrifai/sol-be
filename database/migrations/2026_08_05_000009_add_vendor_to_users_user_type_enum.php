<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pakai Schema Doctrine rebuild untuk cross-DB (MySQL & SQLite).
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY COLUMN user_type ENUM('internal', 'customer', 'vendor') NOT NULL DEFAULT 'internal'");
        }
        // SQLite: tidak perlu ALTER (ENUM di SQLite adalah TEXT tanpa constraint).
        // Vendor users sudah bisa insert karena SQLite tidak enforce ENUM.
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY COLUMN user_type ENUM('internal', 'customer') NOT NULL DEFAULT 'internal'");
        }
    }
};
