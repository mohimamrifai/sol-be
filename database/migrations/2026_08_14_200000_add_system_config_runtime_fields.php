<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (! Schema::hasColumn('bookings', 'draft_expires_at')) {
                $table->timestamp('draft_expires_at')->nullable()->after('status');
            }
        });

        Schema::table('shipments', function (Blueprint $table) {
            if (! Schema::hasColumn('shipments', 'free_storage_origin_days')) {
                $table->unsignedSmallInteger('free_storage_origin_days')->nullable()->after('notes');
            }
            if (! Schema::hasColumn('shipments', 'free_storage_destination_days')) {
                $table->unsignedSmallInteger('free_storage_destination_days')->nullable()->after('free_storage_origin_days');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn(['free_storage_origin_days', 'free_storage_destination_days']);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('draft_expires_at');
        });
    }
};
