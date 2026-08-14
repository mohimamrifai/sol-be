<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_job_orders', function (Blueprint $table) {
            $table->id();
            $table->string('job_order_number', 30)->unique();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->restrictOnDelete();
            $table->string('service_type', 20);
            $table->string('status', 30)->default('draft');
            $table->foreignId('pricing_id')->nullable()->constrained('pricings')->nullOnDelete();
            $table->decimal('vendor_rate', 18, 2)->default(0);
            $table->decimal('additional_cost', 18, 2)->default(0);
            $table->decimal('total_cost', 18, 2)->default(0);
            $table->json('vendor_snapshot')->nullable();
            $table->json('shipment_snapshot')->nullable();
            $table->text('pickup_address')->nullable();
            $table->dateTime('pickup_date')->nullable();
            $table->time('pickup_time')->nullable();
            $table->text('pickup_cargo_info')->nullable();
            $table->text('pickup_remark')->nullable();
            $table->text('delivery_address')->nullable();
            $table->dateTime('delivery_date')->nullable();
            $table->time('delivery_time')->nullable();
            $table->text('delivery_cargo_info')->nullable();
            $table->text('delivery_remark')->nullable();
            $table->foreignId('origin_yard_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('destination_yard_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('train_id')->nullable()->constrained('trains')->nullOnDelete();
            $table->dateTime('departure_at')->nullable();
            $table->string('vehicle_type', 60)->nullable();
            $table->string('vehicle_plate', 30)->nullable();
            $table->string('driver_name', 120)->nullable();
            $table->string('driver_mobile', 30)->nullable();
            $table->text('vehicle_remark')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['shipment_id', 'vendor_id', 'service_type'], 'vendor_job_orders_unique_lane');
            $table->index(['status', 'created_at']);
        });

        Schema::create('vendor_job_order_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_job_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('activity');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_job_order_activities');
        Schema::dropIfExists('vendor_job_orders');
    }
};
