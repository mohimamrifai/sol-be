<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Multi-file attachments for a booking (spec L15: dokumen yang diunggah
     * menjadi bagian dari booking dan akan diteruskan ke proses Shipment).
     */
    public function up(): void
    {
        Schema::create('booking_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('file_path');
            $table->string('original_name');
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('category', 50)->default('general'); // general, msds, invoice, others
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_attachments');
    }
};
