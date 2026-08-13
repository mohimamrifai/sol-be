<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            if (! Schema::hasColumn('shipments', 'internal_pic_id')) {
                $table->foreignId('internal_pic_id')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('shipments', 'train_id')) {
                $table->foreignId('train_id')->nullable()->after('internal_pic_id')->constrained('trains')->nullOnDelete();
            }
            if (! Schema::hasColumn('shipments', 'origin_yard_id')) {
                $table->foreignId('origin_yard_id')->nullable()->after('train_id')->constrained('locations')->nullOnDelete();
            }
            if (! Schema::hasColumn('shipments', 'destination_yard_id')) {
                $table->foreignId('destination_yard_id')->nullable()->after('origin_yard_id')->constrained('locations')->nullOnDelete();
            }
            if (! Schema::hasColumn('shipments', 'planning_notes')) {
                $table->text('planning_notes')->nullable()->after('notes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $cols = ['internal_pic_id', 'train_id', 'origin_yard_id', 'destination_yard_id', 'planning_notes'];
            foreach ($cols as $col) {
                if (Schema::hasColumn('shipments', $col)) {
                    if (in_array($col, ['internal_pic_id', 'train_id', 'origin_yard_id', 'destination_yard_id'], true)) {
                        $table->dropConstrainedForeignId($col);
                    } else {
                        $table->dropColumn($col);
                    }
                }
            }
        });
    }
};
