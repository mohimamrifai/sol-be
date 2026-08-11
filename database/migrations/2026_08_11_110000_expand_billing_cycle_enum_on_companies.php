<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE companies MODIFY COLUMN billing_cycle ENUM(
            'per_shipment',
            'semi_monthly',
            'monthly',
            'half_monthly_1',
            'half_monthly_2',
            'both_half',
            'end_of_month'
        ) NULL DEFAULT 'end_of_month'");
    }

    public function down(): void
    {
        DB::statement("UPDATE companies SET billing_cycle = 'end_of_month' WHERE billing_cycle IN ('per_shipment', 'semi_monthly', 'monthly')");

        DB::statement("ALTER TABLE companies MODIFY COLUMN billing_cycle ENUM(
            'half_monthly_1',
            'half_monthly_2',
            'both_half',
            'end_of_month'
        ) NOT NULL DEFAULT 'end_of_month'");
    }
};
