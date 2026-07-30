<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_packages', function (Blueprint $table): void {
            $table->text('remark')->nullable()->after('package_type');
            $table->string('packing_group', 10)->nullable()->after('un_number');
            $table->string('proper_shipping_name')->nullable()->after('packing_group');
            $table->decimal('flash_point', 8, 2)->nullable()->after('proper_shipping_name');
            $table->text('dg_remark')->nullable()->after('flash_point');
        });

        Schema::table('booking_containers', function (Blueprint $table): void {
            $table->unsignedInteger('quantity')->default(1)->after('container_type_id');
            $table->string('cargo_description')->nullable()->after('gross_weight_kg');
            $table->text('remark')->nullable()->after('cargo_description');
            $table->string('packing_group', 10)->nullable()->after('un_number');
            $table->string('proper_shipping_name')->nullable()->after('packing_group');
            $table->decimal('flash_point', 8, 2)->nullable()->after('proper_shipping_name');
            $table->text('dg_remark')->nullable()->after('flash_point');
        });

        Schema::table('booking_attachments', function (Blueprint $table): void {
            $table->string('document_type', 50)->nullable()->after('category');
            $table->text('remarks')->nullable()->after('document_type');
        });
    }

    public function down(): void
    {
        Schema::table('booking_packages', function (Blueprint $table): void {
            $table->dropColumn(['remark', 'packing_group', 'proper_shipping_name', 'flash_point', 'dg_remark']);
        });

        Schema::table('booking_containers', function (Blueprint $table): void {
            $table->dropColumn([
                'quantity',
                'cargo_description',
                'remark',
                'packing_group',
                'proper_shipping_name',
                'flash_point',
                'dg_remark',
            ]);
        });

        Schema::table('booking_attachments', function (Blueprint $table): void {
            $table->dropColumn(['document_type', 'remarks']);
        });
    }
};

