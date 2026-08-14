<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stations')) {
            Schema::create('stations', function (Blueprint $table) {
                $table->id();
                $table->string('code', 30)->unique();
                $table->string('name');
                $table->string('business_entity', 50);
                $table->string('city')->nullable();
                $table->string('province')->nullable();
                $table->text('address')->nullable();
                $table->enum('status', ['active', 'inactive'])->default('active');
                $table->text('remark')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('yards')) {
            Schema::create('yards', function (Blueprint $table) {
                $table->id();
                $table->string('code', 30)->unique();
                $table->string('name');
                $table->string('business_entity', 50);
                $table->foreignId('station_id')->constrained('stations')->cascadeOnDelete();
                $table->enum('yard_type', ['origin_yard', 'destination_yard', 'hub_yard']);
                $table->enum('status', ['active', 'inactive'])->default('active');
                $table->text('remark')->nullable();
                $table->string('country')->nullable();
                $table->string('province')->nullable();
                $table->string('city')->nullable();
                $table->string('district')->nullable();
                $table->string('postal_code', 20)->nullable();
                $table->text('address')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('routes')) {
            Schema::create('routes', function (Blueprint $table) {
                $table->id();
                $table->string('code', 30)->unique();
                $table->string('business_entity', 50);
                $table->foreignId('origin_station_id')->constrained('stations')->cascadeOnDelete();
                $table->foreignId('destination_station_id')->constrained('stations')->cascadeOnDelete();
                $table->decimal('distance_km', 10, 2);
                $table->unsignedSmallInteger('transit_days');
                $table->enum('status', ['active', 'inactive'])->default('active');
                $table->text('remark')->nullable();
                $table->json('service_types')->nullable();
                $table->json('shipment_coverages')->nullable();
                $table->timestamps();
            });
        }

        $this->repointYardForeignKeys();
    }

    public function down(): void
    {
        if (Schema::hasColumn('container_movements', 'yard_id')) {
            $this->dropForeignIfExists('container_movements', 'yard_id');

            Schema::table('container_movements', function (Blueprint $table) {
                $table->foreign('yard_id')->references('id')->on('locations')->nullOnDelete();
            });
        }

        if (Schema::hasColumn('container_assets', 'current_yard_id')) {
            $this->dropForeignIfExists('container_assets', 'current_yard_id');

            Schema::table('container_assets', function (Blueprint $table) {
                $table->foreign('current_yard_id')->references('id')->on('locations')->nullOnDelete();
            });
        }

        Schema::dropIfExists('routes');
        Schema::dropIfExists('yards');
        Schema::dropIfExists('stations');
    }

    private function repointYardForeignKeys(): void
    {
        if (! Schema::hasTable('yards')) {
            return;
        }

        $validYardIds = DB::table('yards')->pluck('id')->all();

        if (Schema::hasColumn('container_assets', 'current_yard_id')) {
            if ($validYardIds === []) {
                DB::table('container_assets')->whereNotNull('current_yard_id')->update(['current_yard_id' => null]);
            } else {
                DB::table('container_assets')
                    ->whereNotNull('current_yard_id')
                    ->whereNotIn('current_yard_id', $validYardIds)
                    ->update(['current_yard_id' => null]);
            }

            $this->dropForeignIfExists('container_assets', 'current_yard_id');

            Schema::table('container_assets', function (Blueprint $table) {
                $table->foreign('current_yard_id')->references('id')->on('yards')->nullOnDelete();
            });
        }

        if (Schema::hasColumn('container_movements', 'yard_id')) {
            if ($validYardIds === []) {
                DB::table('container_movements')->whereNotNull('yard_id')->update(['yard_id' => null]);
            } else {
                DB::table('container_movements')
                    ->whereNotNull('yard_id')
                    ->whereNotIn('yard_id', $validYardIds)
                    ->update(['yard_id' => null]);
            }

            $this->dropForeignIfExists('container_movements', 'yard_id');

            Schema::table('container_movements', function (Blueprint $table) {
                $table->foreign('yard_id')->references('id')->on('yards')->nullOnDelete();
            });
        }
    }

    private function dropForeignIfExists(string $table, string $column): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            return;
        }

        $database = Schema::getConnection()->getDatabaseName();

        $constraints = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', $database)
            ->where('TABLE_NAME', $table)
            ->where('COLUMN_NAME', $column)
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->pluck('CONSTRAINT_NAME');

        foreach ($constraints as $constraint) {
            DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$constraint}`");
        }
    }
};
