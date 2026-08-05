<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Extend registration form to support:
     *  - country / business_category / business_category_other / business_entity_other / website
     *  - extend business_entity_type enum with 'Perorangan' (and 'Lainnya' as a free-text container
     *    stored in business_entity_other, since 'Lainnya' is a UI sentinel, not a stored enum value)
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            if (! Schema::hasColumn('companies', 'country')) {
                $table->string('country', 80)->nullable()->after('province');
            }
            if (! Schema::hasColumn('companies', 'business_category')) {
                $table->enum('business_category', [
                    'trading', 'manufacturing', 'retail', 'distributor',
                    'e_commerce', 'logistics', 'others',
                ])->nullable();
            }
            if (! Schema::hasColumn('companies', 'business_category_other')) {
                $table->string('business_category_other', 100)->nullable()->after('business_category');
            }
            if (! Schema::hasColumn('companies', 'business_entity_other')) {
                $table->string('business_entity_other', 100)->nullable()->after('business_entity_type');
            }
            if (! Schema::hasColumn('companies', 'website')) {
                $table->string('website', 255)->nullable()->after('phone');
            }
            // district may also be missing (some deployments).
            if (! Schema::hasColumn('companies', 'district')) {
                $table->string('district', 120)->nullable()->after('city');
            }
        });

        // business_entity_type is stored as varchar in the original migration.
        // We widen it to accommodate new values like 'Perorangan' and 'Lainnya'.
        // (Lainnya is a UI sentinel — the free text lives in business_entity_other.)
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE companies MODIFY COLUMN business_entity_type VARCHAR(30) NULL');
        }
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn([
                'country',
                'business_category',
                'business_category_other',
                'business_entity_other',
                'website',
            ]);
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE companies MODIFY COLUMN business_entity_type ENUM('PT','CV','UD','Koperasi','Yayasan','Firma') NULL");
        }
    }
};
