<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_activities', function (Blueprint $table): void {
            $table->id();
            $table->string('subject_type', 100);
            $table->unsignedBigInteger('subject_id');
            $table->string('event_key', 100);
            $table->string('description');
            $table->json('meta')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id', 'occurred_at']);
            $table->index('event_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_activities');
    }
};
