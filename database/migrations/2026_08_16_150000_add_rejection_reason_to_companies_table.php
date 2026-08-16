<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('companies', 'rejection_reason')) {
            return;
        }

        Schema::table('companies', function (Blueprint $table) {
            $table->text('rejection_reason')->nullable()->after('review_notes');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('companies', 'rejection_reason')) {
            return;
        }

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('rejection_reason');
        });
    }
};
