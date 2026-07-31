<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Shipments spec alignment (prompt.md L1-165):
     *   - add `shipment_no` sequential 6-digit counter (SHP000001)
     *   - backfill existing rows ordered by created_at + id
     *   - add `shipper_snapshot` & `consignee_snapshot` JSON columns for immutable
     *     copies taken from Booking at the moment Shipment is created (L14)
     *   - add `cancelled_reason` for the Cancelled status
     */
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            if (! Schema::hasColumn('shipments', 'shipment_no')) {
                $table->unsignedBigInteger('shipment_no')->nullable()->after('id')->unique();
            }
            if (! Schema::hasColumn('shipments', 'shipper_snapshot')) {
                $table->json('shipper_snapshot')->nullable();
            }
            if (! Schema::hasColumn('shipments', 'consignee_snapshot')) {
                $table->json('consignee_snapshot')->nullable();
            }
            if (! Schema::hasColumn('shipments', 'cancelled_reason')) {
                $table->text('cancelled_reason')->nullable();
            }
            if (! Schema::hasColumn('shipments', 'shipment_coverage')) {
                $table->string('shipment_coverage', 32)->nullable()->after('service_type_id');
            }
        });

        $driver = DB::getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            // Assign sequential shipment_no to existing rows in deterministic order.
            DB::statement('
                SET @row := 0;
            ');
            DB::statement('
                UPDATE shipments
                SET shipment_no = (@row := @row + 1)
                ORDER BY created_at ASC, id ASC
            ');
        } elseif ($driver === 'sqlite') {
            // For sqlite, use ROWID via a subquery approach to assign sequential numbers.
            $rows = DB::table('shipments')->orderBy('created_at')->orderBy('id')->get(['id']);
            $i = 1;
            foreach ($rows as $row) {
                DB::table('shipments')->where('id', $row->id)->update(['shipment_no' => $i]);
                $i++;
            }
        } else {
            $rows = DB::table('shipments')->orderBy('created_at')->orderBy('id')->get(['id']);
            $i = 1;
            foreach ($rows as $row) {
                DB::table('shipments')->where('id', $row->id)->update(['shipment_no' => $i]);
                $i++;
            }
        }
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->dropUnique(['shipment_no']);
            $table->dropColumn(['shipment_no', 'shipper_snapshot', 'consignee_snapshot', 'cancelled_reason']);
        });
    }
};
