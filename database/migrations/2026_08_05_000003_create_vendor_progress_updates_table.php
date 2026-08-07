<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_progress_updates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shipment_id');
            $table->text('progress_notes')->nullable();
            $table->text('completion_remark')->nullable();
            $table->unsignedBigInteger('submitted_by');
            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->foreign('shipment_id')
                ->references('id')->on('shipments')
                ->cascadeOnDelete();
            $table->foreign('submitted_by')
                ->references('id')->on('users')
                ->restrictOnDelete();

            $table->index('shipment_id', 'vpu_shipment_idx');
            $table->index(['shipment_id', 'submitted_at'], 'vpu_shipment_submitted_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_progress_updates');
    }
};
