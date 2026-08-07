<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vendor_invoice_id');
            $table->string('payment_number', 60)->unique();
            $table->decimal('amount', 18, 2);
            $table->date('payment_date');
            $table->string('payment_method', 40);
            $table->string('reference_no', 100)->nullable();
            $table->string('status', 30)->default('pending');
            $table->string('receipt_path')->nullable();
            $table->string('transfer_receipt_path')->nullable();
            $table->string('withholding_tax_path')->nullable();
            $table->unsignedBigInteger('paid_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('vendor_invoice_id')
                ->references('id')->on('vendor_invoices')
                ->restrictOnDelete();
            $table->foreign('paid_by')
                ->references('id')->on('users')
                ->nullOnDelete();

            $table->index(['vendor_invoice_id', 'status'], 'vendor_payments_invoice_status_idx');
            $table->index('payment_date', 'vendor_payments_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_payments');
    }
};
