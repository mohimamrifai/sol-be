<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trains', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 30)->nullable()->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('train_cars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('train_id')->constrained('trains')->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 30)->nullable();
            $table->decimal('capacity_weight', 12, 2)->nullable();
            $table->decimal('capacity_cbm', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['train_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('train_cars');
        Schema::dropIfExists('trains');
    }
};

