<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Vendor Transportasi
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 20)->nullable()->unique();
            $table->text('address')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('contact_person')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        // Layanan yang disediakan Vendor
        Schema::create('vendor_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transport_mode_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('origin_location_id')->constrained('locations')->cascadeOnDelete();
            $table->foreignId('destination_location_id')->constrained('locations')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Harga (Buy/Sell/Discount)
        Schema::create('pricings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('container_type_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('price_type', ['buy', 'sell']);
            $table->decimal('price_per_kg', 15, 2)->default(0);
            $table->decimal('price_per_cbm', 15, 2)->default(0);
            $table->decimal('price_per_container', 15, 2)->default(0);
            $table->decimal('minimum_charge', 15, 2)->default(0);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Diskon khusus per customer
        Schema::create('customer_discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_service_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('discount_type', ['percentage', 'fixed'])->default('percentage');
            $table->decimal('discount_value', 15, 2)->default(0);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_discounts');
        Schema::dropIfExists('pricings');
        Schema::dropIfExists('vendor_services');
        Schema::dropIfExists('vendors');
    }
};
