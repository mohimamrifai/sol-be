<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            if (! Schema::hasColumn('companies', 'billing_type')) {
                $table->enum('billing_type', ['prepaid', 'postpaid'])->nullable()->after('monthly_shipment_estimate');
            }
            if (! Schema::hasColumn('companies', 'pricing_type')) {
                $table->enum('pricing_type', ['standard', 'discount'])->nullable()->after('billing_type');
            }
            if (! Schema::hasColumn('companies', 'discount_percent')) {
                $table->decimal('discount_percent', 5, 2)->nullable()->after('pricing_type');
            }
            if (! Schema::hasColumn('companies', 'billing_cycle')) {
                $table->enum('billing_cycle', ['per_shipment', 'semi_monthly', 'monthly'])->nullable()->after('discount_percent');
            }
            if (! Schema::hasColumn('companies', 'payment_term')) {
                $table->enum('payment_term', ['cod', 'net_7', 'net_14', 'net_30', 'net_45', 'net_60'])->nullable()->after('billing_cycle');
            }
            if (! Schema::hasColumn('companies', 'credit_limit')) {
                $table->decimal('credit_limit', 15, 2)->nullable()->after('payment_term');
            }
            if (! Schema::hasColumn('companies', 'current_deposit_balance')) {
                $table->decimal('current_deposit_balance', 15, 2)->default(0)->after('credit_limit');
            }
            if (! Schema::hasColumn('companies', 'outstanding_balance')) {
                $table->decimal('outstanding_balance', 15, 2)->default(0)->after('current_deposit_balance');
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn([
                'billing_type', 'pricing_type', 'discount_percent',
                'billing_cycle', 'payment_term', 'credit_limit',
                'current_deposit_balance', 'outstanding_balance',
            ]);
        });
    }
};
