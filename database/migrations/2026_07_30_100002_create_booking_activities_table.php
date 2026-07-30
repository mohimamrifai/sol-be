<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-booking activity log + timeline (spec L70-83: section 5 & 6).
     * Single table that records:
     *   - system events (created, submitted, approved, rejected, cancelled, duplicated, …)
     *   - user actions (note, edit, etc.)
     *
     * `activity_type` is the discriminator and `payload` carries flexible
     * metadata (e.g. reason text, before/after diffs, attachment ids).
     */
    public function up(): void
    {
        Schema::create('booking_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_role', 50)->nullable(); // customer, internal, system
            $table->string('activity_type', 80); // created, edited, submitted, approved, rejected, cancelled, duplicated, attachment_added, …
            $table->string('title'); // short label rendered in the timeline
            $table->text('description')->nullable(); // optional extra detail for the log view
            $table->json('payload')->nullable(); // free-form extra data
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();
            $table->index(['booking_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_activities');
    }
};
