<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Lokasi (Pelabuhan, Kota, Hub)
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 20)->nullable()->unique();
            $table->enum('type', ['port', 'city', 'hub', 'warehouse'])->default('port');
            $table->string('city')->nullable();
            $table->string('province')->nullable();
            $table->text('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Moda Transportasi (Rail, Sea, Air, Truck)
        Schema::create('transport_modes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 10)->nullable()->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Jenis Layanan (FCL, LCL)
        Schema::create('service_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transport_mode_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 10)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Tipe Kontainer (20ft, 40ft, dll)
        Schema::create('container_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('size', 10);
            $table->decimal('capacity_weight', 10, 2)->nullable();
            $table->decimal('capacity_cbm', 10, 2)->nullable();
            $table->decimal('length', 8, 2)->nullable();
            $table->decimal('width', 8, 2)->nullable();
            $table->decimal('height', 8, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Layanan Tambahan (Pickup, Packing, Handling)
        Schema::create('additional_services', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('category', ['pickup', 'packing', 'handling', 'other'])->default('other');
            $table->text('description')->nullable();
            $table->decimal('base_price', 15, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('additional_services');
        Schema::dropIfExists('container_types');
        Schema::dropIfExists('service_types');
        Schema::dropIfExists('transport_modes');
        Schema::dropIfExists('locations');
    }
};
