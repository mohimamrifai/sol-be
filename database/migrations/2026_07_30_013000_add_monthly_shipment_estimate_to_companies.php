<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add `monthly_shipment_estimate` column for Section 3 of the
     * registration form. Stores the range bucket selected by the
     * customer: '<10', '10-50', '50-100', '>100'.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            if (! Schema::hasColumn('companies', 'monthly_shipment_estimate')) {
                $table->string('monthly_shipment_estimate', 20)
                    ->nullable()
                    ->after('business_category_other');
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('monthly_shipment_estimate');
        });
    }
};
