<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('vendor_invoices', 'vendor_company_id')) {
            Schema::table('vendor_invoices', function (Blueprint $table) {
                $table->unsignedBigInteger('vendor_company_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('vendor_invoices', 'vendor_company_id')) {
            Schema::table('vendor_invoices', function (Blueprint $table) {
                $table->unsignedBigInteger('vendor_company_id')->nullable(false)->change();
            });
        }
    }
};
