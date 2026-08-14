<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('container_assets', function (Blueprint $table) {
            $table->unsignedSmallInteger('manufacture_year')->nullable()->after('max_capacity_cbm');
        });

        Schema::create('container_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('container_asset_id')->constrained('container_assets')->cascadeOnDelete();
            $table->foreignId('shipment_id')->nullable()->constrained('shipments')->nullOnDelete();
            $table->string('activity', 50);
            $table->string('location_from')->nullable();
            $table->string('location_to')->nullable();
            $table->foreignId('yard_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['container_asset_id', 'occurred_at']);
            $table->index(['shipment_id', 'occurred_at']);
        });

        Schema::create('container_maintenances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('container_asset_id')->constrained('container_assets')->cascadeOnDelete();
            $table->enum('maintenance_type', ['repair', 'inspection', 'cleaning']);
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->text('remark')->nullable();
            $table->enum('status', ['scheduled', 'in_progress', 'completed', 'cancelled'])->default('scheduled');
            $table->date('maintenance_date');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('container_maintenances');
        Schema::dropIfExists('container_movements');

        Schema::table('container_assets', function (Blueprint $table) {
            $table->dropColumn('manufacture_year');
        });
    }
};
