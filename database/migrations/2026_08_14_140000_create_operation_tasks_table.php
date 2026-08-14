<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operation_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained('shipments')->cascadeOnDelete();
            $table->enum('operation_type', [
                'pickup',
                'gate_in_origin',
                'loading',
                'train_departure',
                'train_arrival',
                'gate_out_destination',
                'delivery',
                'proof_of_delivery',
            ]);
            $table->enum('status', ['waiting', 'in_progress', 'completed', 'cancelled'])->default('waiting');
            $table->date('planned_date')->nullable();
            $table->timestamp('actual_at')->nullable();
            $table->text('remark')->nullable();
            $table->json('checklist')->nullable();
            $table->foreignId('vendor_job_order_id')->nullable()->constrained('vendor_job_orders')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['shipment_id', 'operation_type']);
            $table->index(['operation_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operation_tasks');
    }
};
