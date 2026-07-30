<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    /**
     * Bring the bookings table in line with the Bookings spec (L1-86):
     *   - status enum is reduced to 4 values: draft, submitted, approved, rejected
     *     (cancelled & confirmed are no longer used)
     *   - add `shipment_coverage` enum (port_to_port, door_to_port, port_to_door, door_to_door)
     *   - add `auto_calculated_*` columns for system-computed weight & dimension
     *     information (volume, volume weight, chargeable weight) per spec L13
     */
    public function up(): void
    {
        // Step 1: normalise data before changing the enum so we never lose rows.
        //   cancelled  -> rejected (preserve the human reason in `rejection_reason`).
        //   confirmed  -> submitted (legacy alias; no business difference).
        Model::unguard();
        DB::table('bookings')
            ->where('status', 'cancelled')
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    $existing = $row->rejection_reason ?: '';
                    $fromNotes = '';
                    if (! empty($row->notes) && str_contains((string) $row->notes, 'Dibatalkan oleh Customer')) {
                        $fromNotes = trim(preg_replace('/^\[System:.*?\]/m', '', (string) $row->notes) ?? '');
                    }
                    $reason = trim(($existing ?: '') . ($fromNotes ? "\n" . $fromNotes : ''));
                    DB::table('bookings')
                        ->where('id', $row->id)
                        ->update([
                            'status' => 'rejected',
                            'rejection_reason' => $reason ?: 'Dibatalkan oleh customer sebelum perubahan sistem.',
                            'updated_at' => now(),
                        ]);
                }
            });

        DB::table('bookings')
            ->where('status', 'confirmed')
            ->update(['status' => 'submitted', 'updated_at' => now()]);

        // Step 2: change the enum (drop confirmed & cancelled).
        // Use raw SQL because Laravel's schema builder cannot drop enum values
        // on most MySQL/MariaDB versions.
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("
                ALTER TABLE `bookings`
                MODIFY COLUMN `status`
                ENUM('draft','submitted','approved','rejected')
                NOT NULL DEFAULT 'draft'
            ");
        }

        // Step 3: add new columns.
        Schema::table('bookings', function (Blueprint $table) {
            // Shipment coverage (spec L29: filter Port to port, Door to port, Port to door, Door to door)
            $table->enum('shipment_coverage', [
                'port_to_port',
                'door_to_port',
                'port_to_door',
                'door_to_door',
            ])->nullable()->after('service_type_id');

            // Auto-calculated cargo metrics (spec L13)
            $table->decimal('total_volume_cbm', 12, 4)->nullable()->after('estimated_cbm');
            $table->decimal('volume_weight_kg', 12, 4)->nullable()->after('total_volume_cbm');
            $table->decimal('chargeable_weight_kg', 12, 4)->nullable()->after('volume_weight_kg');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn([
                'shipment_coverage',
                'total_volume_cbm',
                'volume_weight_kg',
                'chargeable_weight_kg',
            ]);
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("
                ALTER TABLE `bookings`
                MODIFY COLUMN `status`
                ENUM('draft','submitted','confirmed','approved','rejected','cancelled')
                NOT NULL DEFAULT 'draft'
            ");
        }
    }
};
