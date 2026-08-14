<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cargo_categories', function (Blueprint $table) {
            if (! Schema::hasColumn('cargo_categories', 'pricing_multiplier')) {
                $table->decimal('pricing_multiplier', 8, 4)->default(1)->after('description');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cargo_categories', function (Blueprint $table) {
            if (Schema::hasColumn('cargo_categories', 'pricing_multiplier')) {
                $table->dropColumn('pricing_multiplier');
            }
        });
    }
};
