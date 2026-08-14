<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('numbering_formats', function (Blueprint $table) {
            $table->id();
            $table->string('document_type', 30)->unique();
            $table->string('prefix', 20);
            $table->unsignedTinyInteger('running_digits')->default(5);
            $table->string('separator', 5)->default('-');
            $table->enum('reset_period', ['never', 'monthly', 'yearly'])->default('monthly');
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamp('last_reset_at')->nullable();
            $table->timestamps();
        });

        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->string('group', 50)->default('general');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
        Schema::dropIfExists('numbering_formats');
    }
};
