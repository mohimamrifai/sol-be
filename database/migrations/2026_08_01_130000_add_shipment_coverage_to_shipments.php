<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            if (! Schema::hasColumn('shipments', 'shipment_coverage')) {
                $table->string('shipment_coverage', 32)->nullable()->after('service_type_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->dropColumn('shipment_coverage');
        });
    }
};
