<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            if (! Schema::hasColumn('companies', 'manual_payment_enabled')) {
                $table->boolean('manual_payment_enabled')->default(false)->after('postpaid_term_days');
            }
            if (! Schema::hasColumn('companies', 'bank_name')) {
                $table->string('bank_name')->nullable()->after('manual_payment_enabled');
            }
            if (! Schema::hasColumn('companies', 'bank_account_number')) {
                $table->string('bank_account_number')->nullable();
            }
            if (! Schema::hasColumn('companies', 'bank_account_name')) {
                $table->string('bank_account_name')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn([
                'manual_payment_enabled',
                'bank_name',
                'bank_account_number',
                'bank_account_name',
            ]);
        });
    }
};
