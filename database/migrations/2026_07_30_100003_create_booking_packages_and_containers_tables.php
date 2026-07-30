<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-spec L14: DG info and MSDS/SDS documents are stored per Package (LCL)
     * or per Container (FCL), not per booking. We therefore move the DG fields
     * out of the bookings table into these two new tables.
     *
     * Legacy columns on `bookings` (is_dangerous_goods, dg_class_id, un_number,
     * msds_file) are left in place for backwards compatibility with existing
     * data; new writes will populate the per-item tables instead.
     */
    public function up(): void
    {
        // ── Packages (LCL) ────────────────────────────────────────────────
        Schema::create('booking_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence')->default(1);
            $table->string('description')->nullable();
            $table->decimal('length', 10, 2)->nullable();
            $table->decimal('width', 10, 2)->nullable();
            $table->decimal('height', 10, 2)->nullable();
            $table->decimal('weight_kg', 12, 4)->nullable();
            $table->decimal('volume_cbm', 12, 4)->nullable();
            $table->unsignedInteger('piece_count')->default(1);
            $table->string('package_type', 80)->nullable(); // carton, pallet, crate, …
            $table->boolean('is_dangerous_goods')->default(false);
            $table->foreignId('dg_class_id')->nullable()->constrained('dg_classes')->nullOnDelete();
            $table->string('un_number', 50)->nullable();
            $table->string('msds_file_path')->nullable();
            $table->text('dg_notes')->nullable();
            $table->timestamps();
        });

        // ── Containers (FCL) ──────────────────────────────────────────────
        Schema::create('booking_containers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('container_type_id')->nullable()->constrained('container_types')->nullOnDelete();
            $table->unsignedInteger('sequence')->default(1);
            $table->string('container_number', 20)->nullable();
            $table->string('seal_number', 50)->nullable();
            $table->decimal('gross_weight_kg', 12, 4)->nullable();
            $table->decimal('volume_cbm', 12, 4)->nullable();
            $table->enum('equipment_condition', ['CLEAN', 'RESIDUAL'])->nullable();
            $table->decimal('temperature', 8, 2)->nullable();
            $table->boolean('is_dangerous_goods')->default(false);
            $table->foreignId('dg_class_id')->nullable()->constrained('dg_classes')->nullOnDelete();
            $table->string('un_number', 50)->nullable();
            $table->string('msds_file_path')->nullable();
            $table->text('dg_notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_containers');
        Schema::dropIfExists('booking_packages');
    }
};
