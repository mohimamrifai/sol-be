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
            $table->renameColumn('pickup_date', 'departure_date');
            $table->foreignId('cargo_category_id')->nullable()->constrained('cargo_categories')->onDelete('set null')->after('service_type_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropForeign(['cargo_category_id']);
            $table->dropColumn('cargo_category_id');
            $table->renameColumn('departure_date', 'pickup_date');
        });
    }
};
