<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            if (! Schema::hasColumn('shipments', 'cargo_snapshot')) {
                $table->json('cargo_snapshot')->nullable()->after('consignee_snapshot');
            }
        });

        if (! Schema::hasTable('shipment_activities')) {
            Schema::create('shipment_activities', function (Blueprint $table) {
                $table->id();
                $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
                $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('event_key', 80);
                $table->string('description', 1000);
                $table->json('meta')->nullable();
                $table->timestamp('occurred_at');
                $table->timestamps();

                $table->index(['shipment_id', 'occurred_at']);
            });
        }

        if (! Schema::hasTable('shipment_documents')) {
            Schema::create('shipment_documents', function (Blueprint $table) {
                $table->id();
                $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
                $table->enum('kind', ['supporting'])->default('supporting');
                $table->string('file_path');
                $table->string('original_name');
                $table->string('mime_type', 120)->nullable();
                $table->unsignedBigInteger('size')->default(0);
                $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_documents');
        Schema::dropIfExists('shipment_activities');

        Schema::table('shipments', function (Blueprint $table) {
            if (Schema::hasColumn('shipments', 'cargo_snapshot')) {
                $table->dropColumn('cargo_snapshot');
            }
        });
    }
};
