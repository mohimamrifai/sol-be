<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->foreignId('shipper_branch_id')->nullable()->after('shipper_phone')->constrained('branches')->nullOnDelete();
            $table->enum('consignee_type', ['customer_location', 'external'])->default('external')->after('shipper_branch_id');
            $table->foreignId('consignee_branch_id')->nullable()->after('consignee_phone')->constrained('branches')->nullOnDelete();

            $table->json('shipper_snapshot')->nullable()->after('shipper_branch_id');
            $table->json('consignee_snapshot')->nullable()->after('consignee_branch_id');

            $table->date('pickup_date')->nullable()->after('departure_date');
            $table->time('pickup_time')->nullable()->after('pickup_date');
            $table->text('pickup_notes')->nullable()->after('pickup_time');
            $table->text('delivery_notes')->nullable()->after('pickup_notes');

            $table->enum('container_responsibility', ['SOC', 'COC'])->nullable()->after('container_count');
            $table->timestamp('confirmed_terms_at')->nullable()->after('delivery_notes');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('shipper_branch_id');
            $table->dropConstrainedForeignId('consignee_branch_id');

            $table->dropColumn([
                'consignee_type',
                'shipper_snapshot',
                'consignee_snapshot',
                'pickup_date',
                'pickup_time',
                'pickup_notes',
                'delivery_notes',
                'container_responsibility',
                'confirmed_terms_at',
            ]);
        });
    }
};
