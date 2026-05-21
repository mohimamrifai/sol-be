<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (!Schema::hasColumn('bookings', 'is_dangerous_goods')) {
                $table->boolean('is_dangerous_goods')->default(false)->after('cargo_description');
            }
            if (!Schema::hasColumn('bookings', 'dg_class_id')) {
                $table->foreignId('dg_class_id')->nullable()->constrained('dg_classes')->onDelete('set null')->after('is_dangerous_goods');
            }
            if (!Schema::hasColumn('bookings', 'un_number')) {
                $table->string('un_number')->nullable()->after('dg_class_id');
            }
            if (!Schema::hasColumn('bookings', 'msds_file')) {
                $table->string('msds_file')->nullable()->after('un_number');
            }
            if (!Schema::hasColumn('bookings', 'equipment_condition')) {
                $table->enum('equipment_condition', ['CLEAN', 'RESIDUAL'])->nullable()->after('msds_file');
            }
            if (!Schema::hasColumn('bookings', 'temperature')) {
                $table->decimal('temperature', 8, 2)->nullable()->after('equipment_condition');
            }
        });

        Schema::table('shipments', function (Blueprint $table) {
            if (!Schema::hasColumn('shipments', 'is_dangerous_goods')) {
                $table->boolean('is_dangerous_goods')->default(false)->after('service_type_id');
            }
            if (!Schema::hasColumn('shipments', 'dg_class_id')) {
                $table->foreignId('dg_class_id')->nullable()->constrained('dg_classes')->onDelete('set null')->after('is_dangerous_goods');
            }
            if (!Schema::hasColumn('shipments', 'un_number')) {
                $table->string('un_number')->nullable()->after('dg_class_id');
            }
            if (!Schema::hasColumn('shipments', 'msds_file')) {
                $table->string('msds_file')->nullable()->after('un_number');
            }
            if (!Schema::hasColumn('shipments', 'equipment_condition')) {
                $table->enum('equipment_condition', ['CLEAN', 'RESIDUAL'])->nullable()->after('msds_file');
            }
            if (!Schema::hasColumn('shipments', 'temperature')) {
                $table->decimal('temperature', 8, 2)->nullable()->after('equipment_condition');
            }
        });
    }

    public function down(): void
    {
        $tables = ['bookings', 'shipments'];

        foreach ($tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropForeign(['dg_class_id']);
                $table->dropColumn([
                    'is_dangerous_goods', 'dg_class_id', 'un_number',
                    'msds_file', 'equipment_condition', 'temperature'
                ]);
            });
        }
    }
};
