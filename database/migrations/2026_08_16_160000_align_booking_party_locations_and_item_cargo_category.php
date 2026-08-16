<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            if (Schema::hasColumn('bookings', 'shipper_branch_id')) {
                $table->dropConstrainedForeignId('shipper_branch_id');
            }
            if (Schema::hasColumn('bookings', 'consignee_branch_id')) {
                $table->dropConstrainedForeignId('consignee_branch_id');
            }
        });

        Schema::table('bookings', function (Blueprint $table): void {
            if (! Schema::hasColumn('bookings', 'shipper_location_id')) {
                $table->foreignId('shipper_location_id')
                    ->nullable()
                    ->after('shipper_phone')
                    ->constrained('customer_locations')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('bookings', 'consignee_location_id')) {
                $table->foreignId('consignee_location_id')
                    ->nullable()
                    ->after('consignee_phone')
                    ->constrained('customer_locations')
                    ->nullOnDelete();
            }
        });

        Schema::table('booking_packages', function (Blueprint $table): void {
            if (! Schema::hasColumn('booking_packages', 'cargo_category_id')) {
                $table->foreignId('cargo_category_id')
                    ->nullable()
                    ->after('package_type')
                    ->constrained('cargo_categories')
                    ->nullOnDelete();
            }
        });

        Schema::table('booking_containers', function (Blueprint $table): void {
            if (! Schema::hasColumn('booking_containers', 'cargo_category_id')) {
                $table->foreignId('cargo_category_id')
                    ->nullable()
                    ->after('cargo_description')
                    ->constrained('cargo_categories')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('booking_containers', function (Blueprint $table): void {
            if (Schema::hasColumn('booking_containers', 'cargo_category_id')) {
                $table->dropConstrainedForeignId('cargo_category_id');
            }
        });

        Schema::table('booking_packages', function (Blueprint $table): void {
            if (Schema::hasColumn('booking_packages', 'cargo_category_id')) {
                $table->dropConstrainedForeignId('cargo_category_id');
            }
        });

        Schema::table('bookings', function (Blueprint $table): void {
            if (Schema::hasColumn('bookings', 'shipper_location_id')) {
                $table->dropConstrainedForeignId('shipper_location_id');
            }
            if (Schema::hasColumn('bookings', 'consignee_location_id')) {
                $table->dropConstrainedForeignId('consignee_location_id');
            }
        });

        Schema::table('bookings', function (Blueprint $table): void {
            if (! Schema::hasColumn('bookings', 'shipper_branch_id')) {
                $table->foreignId('shipper_branch_id')->nullable()->after('shipper_phone')->constrained('branches')->nullOnDelete();
            }
            if (! Schema::hasColumn('bookings', 'consignee_branch_id')) {
                $table->foreignId('consignee_branch_id')->nullable()->after('consignee_phone')->constrained('branches')->nullOnDelete();
            }
        });
    }
};
