<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pengiriman (Shipment) – dibuat dari Booking yang disetujui
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->string('shipment_number')->unique();
            $table->string('waybill_number')->unique();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('origin_location_id')->constrained('locations');
            $table->foreignId('destination_location_id')->constrained('locations');
            $table->foreignId('transport_mode_id')->constrained();
            $table->foreignId('service_type_id')->constrained();
            $table->enum('status', [
                'created',
                'survey_completed',
                'cargo_received',
                'stuffing_container',
                'container_sealed',
                'departed',
                'arrived',
                'unloading',
                'ready_for_pickup',
                'completed',
                'cancelled',
            ])->default('created');
            $table->date('estimated_departure')->nullable();
            $table->date('estimated_arrival')->nullable();
            $table->date('actual_departure')->nullable();
            $table->date('actual_arrival')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        // Kontainer yang dipakai di dalam shipment
        Schema::create('containers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('container_type_id')->constrained();
            $table->string('container_number')->nullable();
            $table->string('seal_number')->nullable();
            $table->timestamps();
        });

        // Rak (Rack) di dalam kontainer LCL
        Schema::create('racks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('container_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('length', 8, 2)->nullable();
            $table->decimal('width', 8, 2)->nullable();
            $table->decimal('height', 8, 2)->nullable();
            $table->timestamps();
        });

        // Item barang (kargo) di dalam shipment
        Schema::create('shipment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->integer('quantity')->default(1);
            $table->decimal('gross_weight', 10, 2)->default(0);
            $table->decimal('length', 8, 2)->nullable();
            $table->decimal('width', 8, 2)->nullable();
            $table->decimal('height', 8, 2)->nullable();
            $table->decimal('cbm', 10, 4)->nullable();
            $table->boolean('is_fragile')->default(false);
            $table->boolean('is_stackable')->default(true);
            $table->enum('placement_type', ['rack', 'floor'])->default('rack');
            $table->foreignId('container_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('rack_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_items');
        Schema::dropIfExists('racks');
        Schema::dropIfExists('containers');
        Schema::dropIfExists('shipments');
    }
};
