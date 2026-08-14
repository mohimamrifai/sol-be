<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('container_types', function (Blueprint $table) {
            $table->string('code', 20)->nullable()->unique()->after('id');
            $table->enum('category', [
                'dry', 'high_cube', 'reefer', 'open_top', 'flat_rack', 'tank', 'other',
            ])->nullable()->after('size');
            $table->string('iso_code', 20)->nullable()->after('category');
            $table->text('remark')->nullable()->after('is_active');
        });

        Schema::table('service_types', function (Blueprint $table) {
            $table->enum('service_category', [
                'rail_freight', 'pickup_trucking', 'delivery_trucking', 'container_rental',
                'lift_on', 'lift_off', 'storage', 'other',
            ])->nullable()->after('code');
            $table->enum('pricing_basis', [
                'per_trip', 'per_container', 'per_ton', 'per_kg', 'per_cbm',
            ])->nullable()->after('service_category');
        });
    }

    public function down(): void
    {
        Schema::table('container_types', function (Blueprint $table) {
            $table->dropColumn(['code', 'category', 'iso_code', 'remark']);
        });

        Schema::table('service_types', function (Blueprint $table) {
            $table->dropColumn(['service_category', 'pricing_basis']);
        });
    }
};
