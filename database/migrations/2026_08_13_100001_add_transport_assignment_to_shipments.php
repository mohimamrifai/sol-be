<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->foreignId('pickup_vendor_id')->nullable()->after('planning_notes')->constrained('vendors')->nullOnDelete();
            $table->string('pickup_vehicle_type', 60)->nullable()->after('pickup_vendor_id');
            $table->string('pickup_vehicle_plate', 30)->nullable()->after('pickup_vehicle_type');
            $table->string('pickup_driver_name', 120)->nullable()->after('pickup_vehicle_plate');
            $table->string('pickup_driver_mobile', 30)->nullable()->after('pickup_driver_name');
            $table->string('pickup_vendor_pic', 120)->nullable()->after('pickup_driver_mobile');
            $table->dateTime('pickup_scheduled_at')->nullable()->after('pickup_vendor_pic');
            $table->text('pickup_remark')->nullable()->after('pickup_scheduled_at');

            $table->foreignId('delivery_vendor_id')->nullable()->after('pickup_remark')->constrained('vendors')->nullOnDelete();
            $table->string('delivery_vehicle_type', 60)->nullable()->after('delivery_vendor_id');
            $table->string('delivery_vehicle_plate', 30)->nullable()->after('delivery_vehicle_type');
            $table->string('delivery_driver_name', 120)->nullable()->after('delivery_vehicle_plate');
            $table->string('delivery_driver_mobile', 30)->nullable()->after('delivery_driver_name');
            $table->string('delivery_vendor_pic', 120)->nullable()->after('delivery_driver_mobile');
            $table->dateTime('delivery_scheduled_at')->nullable()->after('delivery_vendor_pic');
            $table->text('delivery_remark')->nullable()->after('delivery_scheduled_at');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('delivery_vendor_id');
            $table->dropColumn([
                'delivery_vehicle_type',
                'delivery_vehicle_plate',
                'delivery_driver_name',
                'delivery_driver_mobile',
                'delivery_vendor_pic',
                'delivery_scheduled_at',
                'delivery_remark',
            ]);
            $table->dropConstrainedForeignId('pickup_vendor_id');
            $table->dropColumn([
                'pickup_vehicle_type',
                'pickup_vehicle_plate',
                'pickup_driver_name',
                'pickup_driver_mobile',
                'pickup_vendor_pic',
                'pickup_scheduled_at',
                'pickup_remark',
            ]);
        });
    }
};
