<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('shipments', 'rail_vendor_id')) {
            Schema::table('shipments', function (Blueprint $table) {
                $table->foreignId('rail_vendor_id')->nullable()->after('delivery_remark')->constrained('vendors')->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('vendor_invoices', 'vendor_id')) {
            Schema::table('vendor_invoices', function (Blueprint $table) {
                $table->foreignId('vendor_id')->nullable()->after('vendor_company_id')->constrained('vendors')->nullOnDelete();
                $table->string('vendor_external_number', 80)->nullable()->after('invoice_number');
                $table->date('receive_date')->nullable()->after('invoice_date');
                $table->string('currency', 10)->default('IDR')->after('total_amount');
                $table->string('source', 20)->default('portal')->after('currency');
            });
        }

        if (DB::getDriverName() !== 'sqlite') {
            $this->relaxVendorInvoiceShipmentConstraint();
        }

        if (! Schema::hasTable('vendor_invoice_job_orders')) {
            Schema::create('vendor_invoice_job_orders', function (Blueprint $table) {
                $table->id();
                $table->foreignId('vendor_invoice_id')->constrained()->cascadeOnDelete();
                $table->foreignId('vendor_job_order_id')->constrained()->restrictOnDelete();
                $table->decimal('amount', 18, 2)->default(0);
                $table->timestamps();
                $table->unique(['vendor_invoice_id', 'vendor_job_order_id'], 'vendor_invoice_jo_unique');
            });
        }

        if (! Schema::hasTable('vendor_payment_requests')) {
            Schema::create('vendor_payment_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('vendor_invoice_id')->constrained()->restrictOnDelete();
                $table->string('payment_number', 60)->unique();
                $table->string('status', 30)->default('waiting_approval');
                $table->string('approval_status', 30)->default('waiting_approval');
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->text('approval_remark')->nullable();
                $table->json('vendor_snapshot')->nullable();
                $table->decimal('invoice_amount', 18, 2)->default(0);
                $table->decimal('approved_amount', 18, 2)->default(0);
                $table->decimal('paid_amount', 18, 2)->default(0);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasColumn('vendor_payments', 'vendor_payment_request_id')) {
            Schema::table('vendor_payments', function (Blueprint $table) {
                $table->foreignId('vendor_payment_request_id')->nullable()->after('vendor_invoice_id')
                    ->constrained('vendor_payment_requests')->nullOnDelete();
                $table->string('company_bank', 120)->nullable()->after('payment_method');
                $table->string('payment_proof_path')->nullable()->after('transfer_receipt_path');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('vendor_payments', 'vendor_payment_request_id')) {
            Schema::table('vendor_payments', function (Blueprint $table) {
                $table->dropConstrainedForeignId('vendor_payment_request_id');
                $table->dropColumn(['company_bank', 'payment_proof_path']);
            });
        }

        Schema::dropIfExists('vendor_payment_requests');
        Schema::dropIfExists('vendor_invoice_job_orders');

        if (Schema::hasColumn('vendor_invoices', 'vendor_id')) {
            Schema::table('vendor_invoices', function (Blueprint $table) {
                $table->dropConstrainedForeignId('vendor_id');
                $table->dropColumn(['vendor_external_number', 'receive_date', 'currency', 'source']);
            });
        }

        if (Schema::hasColumn('shipments', 'rail_vendor_id')) {
            Schema::table('shipments', function (Blueprint $table) {
                $table->dropConstrainedForeignId('rail_vendor_id');
            });
        }

        if (DB::getDriverName() !== 'sqlite') {
            $this->restoreVendorInvoiceShipmentConstraint();
        }
    }

    private function relaxVendorInvoiceShipmentConstraint(): void
    {
        if ($this->foreignKeyExists('vendor_invoices', 'vendor_invoices_shipment_id_foreign')) {
            Schema::table('vendor_invoices', function (Blueprint $table) {
                $table->dropForeign(['shipment_id']);
            });
        }

        if (Schema::hasIndex('vendor_invoices', 'vendor_invoices_shipment_unique')) {
            Schema::table('vendor_invoices', function (Blueprint $table) {
                $table->dropUnique('vendor_invoices_shipment_unique');
            });
        }

        Schema::table('vendor_invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('shipment_id')->nullable()->change();
            $table->unsignedBigInteger('vendor_company_id')->nullable()->change();
        });

        if (! $this->foreignKeyExists('vendor_invoices', 'vendor_invoices_shipment_id_foreign')) {
            Schema::table('vendor_invoices', function (Blueprint $table) {
                $table->foreign('shipment_id')->references('id')->on('shipments')->restrictOnDelete();
            });
        }
    }

    private function restoreVendorInvoiceShipmentConstraint(): void
    {
        if ($this->foreignKeyExists('vendor_invoices', 'vendor_invoices_shipment_id_foreign')) {
            Schema::table('vendor_invoices', function (Blueprint $table) {
                $table->dropForeign(['shipment_id']);
            });
        }

        Schema::table('vendor_invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('shipment_id')->nullable(false)->change();
            $table->unsignedBigInteger('vendor_company_id')->nullable(false)->change();
        });

        if (! Schema::hasIndex('vendor_invoices', 'vendor_invoices_shipment_unique')) {
            Schema::table('vendor_invoices', function (Blueprint $table) {
                $table->unique('shipment_id', 'vendor_invoices_shipment_unique');
            });
        }

        Schema::table('vendor_invoices', function (Blueprint $table) {
            $table->foreign('shipment_id')->references('id')->on('shipments')->restrictOnDelete();
        });
    }

    private function foreignKeyExists(string $table, string $foreignKey): bool
    {
        $connection = Schema::getConnection();
        $database = $connection->getDatabaseName();

        $result = $connection->select(
            'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ?',
            [$database, $table, $foreignKey, 'FOREIGN KEY']
        );

        return count($result) > 0;
    }
};
