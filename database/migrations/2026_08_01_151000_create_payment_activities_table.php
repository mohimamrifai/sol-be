<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payment_activities')) {
            return;
        }

        Schema::create('payment_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_key', 64);
            $table->string('description');
            $table->json('meta')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['payment_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_activities');
    }
};
