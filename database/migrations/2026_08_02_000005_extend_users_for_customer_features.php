<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'feature_access')) {
                $table->json('feature_access')->nullable()->after('status');
            }
            if (! Schema::hasColumn('users', 'last_login_at')) {
                $table->timestamp('last_login_at')->nullable()->after('feature_access');
            }
        });

        Schema::create('user_location_access', function (Blueprint $table): void {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_location_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'customer_location_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_location_access');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['feature_access', 'last_login_at']);
        });
    }
};
