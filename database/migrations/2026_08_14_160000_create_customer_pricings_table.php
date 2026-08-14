<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_pricings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('origin_location_id')->constrained('locations')->cascadeOnDelete();
            $table->foreignId('destination_location_id')->constrained('locations')->cascadeOnDelete();
            $table->foreignId('cargo_category_id')->constrained('cargo_categories')->cascadeOnDelete();
            $table->enum('service_type', ['lcl', 'fcl']);
            $table->string('shipment_coverage', 30);
            $table->enum('pricing_basis', ['per_kg', 'per_ton', 'per_cbm', 'per_container']);
            $table->decimal('rate', 15, 2);
            $table->decimal('minimum_charge', 15, 2)->nullable();
            $table->string('currency', 3)->default('IDR');
            $table->foreignId('container_type_id')->nullable()->constrained('container_types')->nullOnDelete();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->text('remark')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });

        Schema::create('customer_pricing_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_pricing_id')->constrained('customer_pricings')->cascadeOnDelete();
            $table->foreignId('additional_charge_id')->constrained('additional_charges')->cascadeOnDelete();
            $table->enum('charge_type', ['fixed', 'percentage']);
            $table->decimal('amount', 15, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_pricing_charges');
        Schema::dropIfExists('customer_pricings');
    }
};
