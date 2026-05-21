<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->enum('payment_type', ['prepaid', 'postpaid'])
                ->default('postpaid')
                ->after('billing_cycle');
            $table->unsignedSmallInteger('postpaid_term_days')
                ->nullable()
                ->after('payment_type');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['payment_type', 'postpaid_term_days']);
        });
    }
};

