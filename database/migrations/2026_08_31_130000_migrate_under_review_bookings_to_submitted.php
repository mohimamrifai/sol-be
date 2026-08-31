<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * `under_review` is no longer a writable booking status. Align legacy rows with `submitted`.
     */
    public function up(): void
    {
        DB::table('bookings')
            ->where('status', 'under_review')
            ->update(['status' => 'submitted', 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Cannot reliably restore which submitted rows were previously under_review.
    }
};
