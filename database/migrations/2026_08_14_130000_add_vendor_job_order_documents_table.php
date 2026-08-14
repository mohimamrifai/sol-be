<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vendor_job_order_documents')) {
            Schema::create('vendor_job_order_documents', function (Blueprint $table) {
                $table->id();
                $table->foreignId('vendor_job_order_id')->constrained()->cascadeOnDelete();
                $table->string('document_type', 40)->default('supporting');
                $table->string('file_path');
                $table->string('original_name');
                $table->string('mime_type', 120)->nullable();
                $table->unsignedBigInteger('size')->default(0);
                $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_job_order_documents');
    }
};
