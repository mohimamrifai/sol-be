<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('train_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('business_entity', 50);
            $table->string('train_number', 50);
            $table->foreignId('route_id')->constrained('routes')->cascadeOnDelete();
            $table->dateTime('departure_at');
            $table->dateTime('eta_at');
            $table->unsignedInteger('max_containers')->nullable();
            $table->enum('status', ['upcoming', 'departed', 'completed', 'cancelled'])->default('upcoming');
            $table->text('remark')->nullable();
            $table->timestamps();
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->foreignId('train_schedule_id')
                ->nullable()
                ->after('train_id')
                ->constrained('train_schedules')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('train_schedule_id');
        });

        Schema::dropIfExists('train_schedules');
    }
};
