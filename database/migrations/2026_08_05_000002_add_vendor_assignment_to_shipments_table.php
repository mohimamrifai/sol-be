<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->unsignedBigInteger('vendor_company_id')->nullable()->after('company_id');
            $table->string('vendor_status', 40)->nullable()->after('status');
            $table->timestamp('accepted_at')->nullable()->after('vendor_status');
            $table->timestamp('completion_submitted_at')->nullable()->after('accepted_at');
            $table->timestamp('completion_verified_at')->nullable()->after('completion_submitted_at');
            $table->text('completion_remark')->nullable()->after('completion_verified_at');
            $table->text('vendor_rejection_reason')->nullable()->after('completion_remark');

            $table->foreign('vendor_company_id')
                ->references('id')->on('companies')
                ->nullOnDelete();

            $table->index('vendor_company_id', 'shipments_vendor_company_idx');
            $table->index(['vendor_company_id', 'vendor_status'], 'shipments_vendor_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropForeign(['vendor_company_id']);
            $table->dropIndex('shipments_vendor_company_idx');
            $table->dropIndex('shipments_vendor_status_idx');
            $table->dropColumn([
                'vendor_company_id',
                'vendor_status',
                'accepted_at',
                'completion_submitted_at',
                'completion_verified_at',
                'completion_remark',
                'vendor_rejection_reason',
            ]);
        });
    }
};
