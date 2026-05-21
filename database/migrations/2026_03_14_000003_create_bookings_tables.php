<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Booking (Pemesanan)
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('booking_number')->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('origin_location_id')->constrained('locations');
            $table->foreignId('destination_location_id')->constrained('locations');
            $table->foreignId('transport_mode_id')->constrained();
            $table->foreignId('service_type_id')->constrained();
            $table->foreignId('container_type_id')->nullable()->constrained();
            $table->integer('container_count')->default(1);
            $table->decimal('estimated_weight', 10, 2)->nullable();
            $table->decimal('estimated_cbm', 10, 2)->nullable();
            $table->text('cargo_description')->nullable();
            $table->date('pickup_date')->nullable();
            $table->decimal('estimated_price', 15, 2)->nullable();
            $table->enum('status', ['draft', 'submitted', 'confirmed', 'approved', 'rejected', 'cancelled'])->default('draft');
            $table->text('rejection_reason')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Pivot: Booking + Layanan Tambahan
        Schema::create('booking_additional_service', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('additional_service_id')->constrained()->cascadeOnDelete();
            $table->text('notes')->nullable();
            $table->decimal('price', 15, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_additional_service');
        Schema::dropIfExists('bookings');
    }
};
