<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FSD admin bookings grid/filter expects a distinct `cancelled` status.
     * Customer cancel and draft expiry should write this status instead of `rejected`.
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->text('cancellation_reason')->nullable()->after('rejection_reason');
        });

        DB::table('bookings')
            ->where('status', 'rejected')
            ->where('rejection_reason', 'like', '[Customer cancel]%')
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    $reason = preg_replace('/^\[Customer cancel\]\s*/', '', (string) $row->rejection_reason);
                    DB::table('bookings')
                        ->where('id', $row->id)
                        ->update([
                            'status' => 'cancelled',
                            'cancellation_reason' => $reason !== '' ? $reason : null,
                            'rejection_reason' => null,
                            'updated_at' => now(),
                        ]);
                }
            });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("
                ALTER TABLE `bookings`
                MODIFY COLUMN `status`
                ENUM('draft','submitted','approved','rejected','cancelled')
                NOT NULL DEFAULT 'draft'
            ");
        }
    }

    public function down(): void
    {
        DB::table('bookings')
            ->where('status', 'cancelled')
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    $reason = $row->cancellation_reason
                        ? '[Customer cancel] '.$row->cancellation_reason
                        : 'Dibatalkan.';
                    DB::table('bookings')
                        ->where('id', $row->id)
                        ->update([
                            'status' => 'rejected',
                            'rejection_reason' => $reason,
                            'updated_at' => now(),
                        ]);
                }
            });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("
                ALTER TABLE `bookings`
                MODIFY COLUMN `status`
                ENUM('draft','submitted','approved','rejected')
                NOT NULL DEFAULT 'draft'
            ");
        }

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('cancellation_reason');
        });
    }
};
