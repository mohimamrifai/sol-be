<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CustomerLocation;
use Illuminate\Support\Facades\DB;

class LocationCodeService
{
    /**
     * Generate next unique location code for the given company.
     *
     * Pattern: {COMPANY_ALIAS}{SEQ 4 digit}, e.g. JKT0001, SBY0002.
     * The sequence is per-company and protected by a row lock on the company
     * to prevent race conditions.
     */
    public function next(int $companyId): string
    {
        return DB::transaction(function () use ($companyId) {
            $company = Company::where('id', $companyId)->lockForUpdate()->firstOrFail();
            $alias = $this->resolveAlias($company);
            $prefix = $alias.'-';

            $latest = CustomerLocation::where('company_id', $companyId)
                ->where('code', 'like', $prefix.'%')
                ->lockForUpdate()
                ->orderByDesc('id')
                ->first();

            $nextSeq = 1;
            if ($latest) {
                $tail = substr($latest->code, strlen($prefix));
                if (is_numeric($tail)) {
                    $nextSeq = ((int) $tail) + 1;
                }
            }

            return $prefix.str_pad((string) $nextSeq, 4, '0', STR_PAD_LEFT);
        });
    }

    private function resolveAlias(Company $company): string
    {
        $code = strtoupper((string) ($company->company_code ?? ''));
        $code = preg_replace('/[^A-Z0-9]/', '', $code) ?? '';
        if (strlen($code) < 3) {
            $code = 'CMP'.substr($code, 0, max(0, 3 - strlen('CMP')));
        }

        return substr($code, 0, 3);
    }
}
