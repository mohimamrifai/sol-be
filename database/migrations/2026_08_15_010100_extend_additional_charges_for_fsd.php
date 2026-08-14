<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('additional_charges', function (Blueprint $table) {
            $table->string('charge_category', 30)->default('other')->after('code');
            $table->string('pricing_basis', 30)->default('per_shipment')->after('charge_category');
        });
    }

    public function down(): void
    {
        Schema::table('additional_charges', function (Blueprint $table) {
            $table->dropColumn(['charge_category', 'pricing_basis']);
        });
    }
};
