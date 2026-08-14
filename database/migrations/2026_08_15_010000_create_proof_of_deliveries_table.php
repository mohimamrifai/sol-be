<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proof_of_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('pod_number', 30)->unique();
            $table->string('status', 30)->default('waiting_pod');
            $table->string('receiver_name')->nullable();
            $table->string('receiver_position')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->text('remark')->nullable();
            $table->timestamp('pod_date')->nullable();
            $table->string('signed_pod_path')->nullable();
            $table->string('delivery_photo_path')->nullable();
            $table->json('other_documents')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('verification_notes')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proof_of_deliveries');
    }
};
