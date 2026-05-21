<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE `locations` MODIFY `type` ENUM('port','city','hub','warehouse','station','airport','terminal') NOT NULL DEFAULT 'port'");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE `locations` MODIFY `type` ENUM('port','city','hub','warehouse') NOT NULL DEFAULT 'port'");
    }
};
