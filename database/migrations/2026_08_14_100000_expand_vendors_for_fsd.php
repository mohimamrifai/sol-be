<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->string('business_entity', 20)->default('company')->after('code');
            $table->json('vendor_types')->nullable()->after('business_entity');
            $table->string('vendor_category', 30)->nullable()->after('vendor_types');
            $table->string('npwp', 30)->nullable()->after('vendor_category');
            $table->string('website')->nullable()->after('email');
            $table->text('remark')->nullable()->after('website');
            $table->string('country', 80)->default('Indonesia')->after('address');
            $table->string('province', 120)->nullable()->after('country');
            $table->string('city', 120)->nullable()->after('province');
            $table->string('district', 120)->nullable()->after('city');
            $table->string('postal_code', 20)->nullable()->after('district');
            $table->string('payment_terms', 30)->nullable()->after('postal_code');
            $table->string('payment_method', 30)->default('transfer')->after('payment_terms');
            $table->string('bank_name')->nullable()->after('payment_method');
            $table->string('bank_account_number', 40)->nullable()->after('bank_name');
            $table->string('account_holder')->nullable()->after('bank_account_number');
            $table->string('tax_status', 20)->nullable()->after('account_holder');
        });

        Schema::create('vendor_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('position')->nullable();
            $table->string('email')->nullable();
            $table->string('mobile', 30);
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('pricings', function (Blueprint $table) {
            $table->string('service_category', 40)->nullable()->after('vendor_service_id');
            $table->string('pricing_basis', 30)->nullable()->after('service_category');
            $table->string('vehicle_type', 60)->nullable()->after('container_type_id');
            $table->decimal('unit_price', 15, 2)->nullable()->after('price_per_container');
            $table->text('remark')->nullable()->after('minimum_charge');
            $table->foreignId('created_by_id')->nullable()->after('remark')->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('pricing_group_id')->nullable()->after('created_by_id');
            $table->index('pricing_group_id');
        });

        Schema::create('pricing_activities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pricing_group_id');
            $table->foreignId('pricing_id')->nullable()->constrained('pricings')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('activity');
            $table->timestamps();
            $table->index('pricing_group_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_activities');
        Schema::table('pricings', function (Blueprint $table) {
            $table->dropForeign(['created_by_id']);
            $table->dropIndex(['pricing_group_id']);
            $table->dropColumn([
                'service_category', 'pricing_basis', 'vehicle_type', 'unit_price',
                'remark', 'created_by_id', 'pricing_group_id',
            ]);
        });
        Schema::dropIfExists('vendor_contacts');
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn([
                'business_entity', 'vendor_types', 'vendor_category', 'npwp', 'website', 'remark',
                'country', 'province', 'city', 'district', 'postal_code',
                'payment_terms', 'payment_method', 'bank_name', 'bank_account_number',
                'account_holder', 'tax_status',
            ]);
        });
    }
};
