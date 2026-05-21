<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('additional_services', function (Blueprint $table) {
            $table->string('code', 30)->nullable()->unique()->after('name');
        });

        // Backfill codes for mandatory seeded services so they are stable identifiers.
        $codes = [
            'Free Storage 5 Hari (Origin & Destination)' => 'FREE_STORAGE_FCL',
            'LOLO (Lift On-Lift Off)'                    => 'LOLO',
            'Container Rent'                             => 'CONTAINER_RENT',
            'Free Storage 1 Hari (Origin & Destination)' => 'FREE_STORAGE_LCL',
        ];

        foreach ($codes as $name => $code) {
            DB::table('additional_services')
                ->where('name', $name)
                ->whereNull('code')
                ->update(['code' => $code]);
        }
    }

    public function down(): void
    {
        Schema::table('additional_services', function (Blueprint $table) {
            $table->dropColumn('code');
        });
    }
};
