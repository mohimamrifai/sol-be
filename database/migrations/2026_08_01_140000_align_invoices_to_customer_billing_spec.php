<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            if (! Schema::hasColumn('invoices', 'company_snapshot')) {
                $table->json('company_snapshot')->nullable();
            }
            if (! Schema::hasColumn('invoices', 'shipment_snapshot')) {
                $table->json('shipment_snapshot')->nullable();
            }
        });

        $driver = DB::getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            // 1) Migrate legacy statuses before altering enum.
            if (Schema::hasColumn('invoices', 'status')) {
                DB::table('invoices')->where('status', 'unpaid')->update(['status' => 'issued']);
                DB::table('invoices')->where('status', 'overdue')->update(['status' => 'issued']);
            }

            // 2) Ensure issued_date and due_date can be nullable (needed for draft).
            // Note: `change()` requires doctrine/dbal; use raw SQL to stay lightweight.
            DB::statement('ALTER TABLE invoices MODIFY issued_date DATE NULL');
            DB::statement('ALTER TABLE invoices MODIFY due_date DATE NULL');

            // 3) Replace enum values to match customer-facing statuses.
            DB::statement("
                ALTER TABLE invoices
                MODIFY status ENUM('draft','issued','partially_paid','paid','cancelled')
                NOT NULL DEFAULT 'draft'
            ");

            // 4) Normalize legacy paid/cancelled values into new enum.
            DB::table('invoices')->where('status', 'paid')->update(['status' => 'paid']);
            DB::table('invoices')->where('status', 'cancelled')->update(['status' => 'cancelled']);

            // 5) For existing rows where issued_date is present but status is draft, set it to issued.
            DB::statement("
                UPDATE invoices
                SET status = 'issued'
                WHERE status = 'draft' AND issued_date IS NOT NULL
            ");
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            // Best-effort revert to legacy enum; keep data safe (map to closest legacy states).
            DB::statement("
                UPDATE invoices
                SET status = 'unpaid'
                WHERE status IN ('draft','issued','partially_paid')
            ");

            DB::statement("
                ALTER TABLE invoices
                MODIFY status ENUM('unpaid','paid','overdue','cancelled')
                NOT NULL DEFAULT 'unpaid'
            ");

            DB::statement('ALTER TABLE invoices MODIFY issued_date DATE NOT NULL');
            DB::statement('ALTER TABLE invoices MODIFY due_date DATE NOT NULL');
        }

        Schema::table('invoices', function (Blueprint $table): void {
            $cols = [];
            if (Schema::hasColumn('invoices', 'company_snapshot')) $cols[] = 'company_snapshot';
            if (Schema::hasColumn('invoices', 'shipment_snapshot')) $cols[] = 'shipment_snapshot';
            if ($cols !== []) $table->dropColumn($cols);
        });
    }
};

