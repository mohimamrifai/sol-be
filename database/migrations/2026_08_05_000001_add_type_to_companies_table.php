<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('type', 20)->default('customer')->after('id');
            $table->json('service_categories')->nullable()->after('business_category_other');
            $table->string('pic_name')->nullable()->after('phone');
            $table->string('pic_email')->nullable()->after('pic_name');
            $table->string('pic_mobile', 30)->nullable()->after('pic_email');

            $table->index(['type', 'status'], 'companies_type_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropIndex('companies_type_status_idx');
            $table->dropColumn(['type', 'service_categories', 'pic_name', 'pic_email', 'pic_mobile']);
        });
    }
};
