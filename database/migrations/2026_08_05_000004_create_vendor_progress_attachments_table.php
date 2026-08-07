<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_progress_attachments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vendor_progress_update_id');
            $table->string('file_path');
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size')->default(0);
            $table->timestamps();

            $table->foreign('vendor_progress_update_id')
                ->references('id')->on('vendor_progress_updates')
                ->cascadeOnDelete();

            $table->index('vendor_progress_update_id', 'vpa_update_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_progress_attachments');
    }
};
