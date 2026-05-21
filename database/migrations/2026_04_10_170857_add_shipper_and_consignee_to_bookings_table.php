<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('shipper_name')->nullable()->after('pickup_date');
            $table->text('shipper_address')->nullable()->after('shipper_name');
            $table->string('shipper_phone')->nullable()->after('shipper_address');
            $table->string('consignee_name')->nullable()->after('shipper_phone');
            $table->text('consignee_address')->nullable()->after('consignee_name');
            $table->string('consignee_phone')->nullable()->after('consignee_address');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn([
                'shipper_name',
                'shipper_address',
                'shipper_phone',
                'consignee_name',
                'consignee_address',
                'consignee_phone',
            ]);
        });
    }
};
