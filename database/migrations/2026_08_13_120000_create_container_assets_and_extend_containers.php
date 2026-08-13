<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('container_assets', function (Blueprint $table) {
            $table->id();
            $table->string('container_number')->unique();
            $table->foreignId('container_type_id')->constrained('container_types')->cascadeOnDelete();
            $table->enum('ownership', ['company', 'vendor'])->default('company');
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->foreignId('current_yard_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->enum('status', ['available', 'reserved', 'in_transit', 'maintenance', 'inactive'])->default('available');
            $table->decimal('max_payload_kg', 12, 2)->nullable();
            $table->decimal('max_capacity_cbm', 12, 2)->nullable();
            $table->text('remark')->nullable();
            $table->timestamps();
        });

        Schema::table('containers', function (Blueprint $table) {
            $table->enum('ownership', ['company', 'vendor', 'customer'])->nullable()->after('seal_number');
            $table->enum('assignment_status', ['waiting', 'assigned'])->default('waiting')->after('ownership');
            $table->foreignId('container_asset_id')->nullable()->after('assignment_status')->constrained('container_assets')->nullOnDelete();
            $table->unsignedSmallInteger('slot_sequence')->nullable()->after('container_asset_id');
            $table->text('remark')->nullable()->after('slot_sequence');
        });
    }

    public function down(): void
    {
        Schema::table('containers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('container_asset_id');
            $table->dropColumn(['ownership', 'assignment_status', 'slot_sequence', 'remark']);
        });

        Schema::dropIfExists('container_assets');
    }
};
