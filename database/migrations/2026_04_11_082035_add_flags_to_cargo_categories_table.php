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
        Schema::table('cargo_categories', function (Blueprint $table) {
            if (!Schema::hasColumn('cargo_categories', 'requires_temperature')) {
                $table->boolean('requires_temperature')->default(false)->after('description');
            }
            if (!Schema::hasColumn('cargo_categories', 'is_project_cargo')) {
                $table->boolean('is_project_cargo')->default(false)->after('requires_temperature');
            }
            if (!Schema::hasColumn('cargo_categories', 'is_liquid')) {
                $table->boolean('is_liquid')->default(false)->after('is_project_cargo');
            }
            if (!Schema::hasColumn('cargo_categories', 'is_food')) {
                $table->boolean('is_food')->default(false)->after('is_liquid');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cargo_categories', function (Blueprint $table) {
            $table->dropColumn(['requires_temperature', 'is_project_cargo', 'is_liquid', 'is_food']);
        });
    }
};
