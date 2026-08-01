<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            if (! Schema::hasColumn('payments', 'payment_number')) {
                $table->unsignedBigInteger('payment_number')->nullable()->after('invoice_id');
                $table->unique('payment_number');
            }
            if (! Schema::hasColumn('payments', 'expired_at')) {
                $table->timestamp('expired_at')->nullable()->after('paid_at');
            }
            if (! Schema::hasColumn('payments', 'method')) {
                $table->string('method', 32)->default('midtrans')->after('payment_type');
            }
            if (! Schema::hasColumn('payments', 'manual_status')) {
                $table->string('manual_status', 32)->default('unsubmitted')->after('method');
            }
            if (! Schema::hasColumn('payments', 'manual_payment_date')) {
                $table->date('manual_payment_date')->nullable();
            }
            if (! Schema::hasColumn('payments', 'manual_bank_name')) {
                $table->string('manual_bank_name')->nullable();
            }
            if (! Schema::hasColumn('payments', 'manual_reference_number')) {
                $table->string('manual_reference_number')->nullable();
            }
            if (! Schema::hasColumn('payments', 'manual_remark')) {
                $table->text('manual_remark')->nullable();
            }
            if (! Schema::hasColumn('payments', 'manual_submitted_at')) {
                $table->timestamp('manual_submitted_at')->nullable();
            }
            if (! Schema::hasColumn('payments', 'manual_verified_by')) {
                $table->foreignId('manual_verified_by')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('payments', 'manual_verified_at')) {
                $table->timestamp('manual_verified_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropUnique(['payment_number']);
            $table->dropColumn([
                'payment_number',
                'expired_at',
                'method',
                'manual_status',
                'manual_payment_date',
                'manual_bank_name',
                'manual_reference_number',
                'manual_remark',
                'manual_submitted_at',
                'manual_verified_by',
                'manual_verified_at',
            ]);
        });
    }
};
