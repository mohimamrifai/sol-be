<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_invoice_attachments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vendor_invoice_id');
            $table->string('file_path');
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size')->default(0);
            $table->string('kind', 30)->default('supporting');
            $table->timestamps();

            $table->foreign('vendor_invoice_id')
                ->references('id')->on('vendor_invoices')
                ->cascadeOnDelete();

            $table->index('vendor_invoice_id', 'via_invoice_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_invoice_attachments');
    }
};
