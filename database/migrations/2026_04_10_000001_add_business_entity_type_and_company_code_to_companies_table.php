<?php

use App\Models\Company;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('business_entity_type', 20)->nullable()->after('name');
            $table->string('company_code', 10)->nullable()->after('business_entity_type');
        });

        Company::withTrashed()
            ->whereNull('company_code')
            ->orderBy('id')
            ->chunkById(200, function ($companies) {
                foreach ($companies as $company) {
                    $company->company_code = Company::generateUniqueCompanyCode($company->name);
                    $company->saveQuietly();
                }
            });

        Schema::table('companies', function (Blueprint $table) {
            $table->unique('name');
            $table->unique('company_code');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropUnique(['name']);
            $table->dropUnique(['company_code']);
            $table->dropColumn(['business_entity_type', 'company_code']);
        });
    }
};
