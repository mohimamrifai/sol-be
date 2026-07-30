<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Extend companies.status enum with 'rejected' to support the
     * Registration Approval Flow:
     *   pending  -> review requested
     *   active   -> approved
     *   inactive -> manually deactivated by internal
     *   rejected -> review denied (new)
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE companies MODIFY COLUMN status ENUM('pending','active','inactive','rejected') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        // Revert rejected rows back to inactive so the previous enum stays valid.
        DB::table('companies')->where('status', 'rejected')->update(['status' => 'inactive']);

        DB::statement("ALTER TABLE companies MODIFY COLUMN status ENUM('pending','active','inactive') NOT NULL DEFAULT 'pending'");
    }
};
