<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payment_proof_attachments')) {
            return;
        }

        Schema::create('payment_proof_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('file_path');
            $table->string('original_name');
            $table->string('mime_type', 64)->nullable();
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('category', 32)->default('payment_proof');
            $table->timestamps();

            $table->index('payment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_proof_attachments');
    }
};
