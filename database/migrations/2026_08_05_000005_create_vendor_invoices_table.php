<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vendor_company_id');
            $table->unsignedBigInteger('shipment_id');
            $table->string('invoice_number', 60)->unique();
            $table->date('invoice_date');
            $table->date('due_date');
            $table->decimal('invoice_amount', 18, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('total_amount', 18, 2)->default(0);
            $table->string('status', 30)->default('draft');
            $table->text('notes')->nullable();
            $table->string('file_path')->nullable();
            $table->string('tax_invoice_path')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('vendor_company_id')
                ->references('id')->on('companies')
                ->restrictOnDelete();
            $table->foreign('shipment_id')
                ->references('id')->on('shipments')
                ->restrictOnDelete();
            $table->foreign('created_by')
                ->references('id')->on('users')
                ->restrictOnDelete();
            $table->foreign('reviewed_by')
                ->references('id')->on('users')
                ->nullOnDelete();

            $table->unique('shipment_id', 'vendor_invoices_shipment_unique');
            $table->index(['vendor_company_id', 'status'], 'vendor_invoices_vendor_status_idx');
            $table->index('invoice_date', 'vendor_invoices_invoice_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_invoices');
    }
};
