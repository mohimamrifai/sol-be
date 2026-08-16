<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('vendor_payment_documents')) {
            return;
        }

        Schema::create('vendor_payment_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_payment_request_id')->constrained('vendor_payment_requests')->cascadeOnDelete();
            $table->string('document_type', 40)->default('other');
            $table->string('file_path');
            $table->string('original_name');
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_payment_documents');
    }
};
