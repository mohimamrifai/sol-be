<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->foreignId('sales_pic_id')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->foreignId('account_manager_id')->nullable()->after('sales_pic_id')->constrained('users')->nullOnDelete();
            $table->text('review_notes')->nullable()->after('account_manager_id');
            $table->timestamp('reviewed_at')->nullable()->after('review_notes');
            $table->foreignId('reviewed_by')->nullable()->after('reviewed_at')->constrained('users')->nullOnDelete();
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE companies MODIFY COLUMN status ENUM('pending','active','suspended','inactive','rejected') NOT NULL DEFAULT 'pending'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::table('companies')->where('status', 'suspended')->update(['status' => 'inactive']);
            DB::statement("ALTER TABLE companies MODIFY COLUMN status ENUM('pending','active','inactive','rejected') NOT NULL DEFAULT 'pending'");
        }

        Schema::table('companies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['review_notes', 'reviewed_at']);
            $table->dropConstrainedForeignId('account_manager_id');
            $table->dropConstrainedForeignId('sales_pic_id');
        });
    }
};
