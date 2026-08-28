<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CustomerLocation;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LocationCodeService
{
    /**
     * Generate a short unique location code within a customer (FSD: HO, SBY, JKT, JHO, SWH).
     */
    public function next(int $companyId, string $name, string $type): string
    {
        return DB::transaction(function () use ($companyId, $name, $type) {
            Company::whereKey($companyId)->lockForUpdate()->firstOrFail();

            foreach ($this->buildCandidates($name, $type) as $candidate) {
                if (! $this->exists($companyId, $candidate)) {
                    return $candidate;
                }
            }

            for ($i = 2; $i <= 99; $i++) {
                $base = $this->buildCandidates($name, $type)[0] ?? 'LC';
                $base = substr($base, 0, max(1, 3 - strlen((string) $i)));
                $code = $base.$i;
                if (strlen($code) <= 3 && ! $this->exists($companyId, $code)) {
                    return $code;
                }
            }

            throw new RuntimeException('Unable to generate unique location code.');
        });
    }

    /**
     * @return list<string>
     */
    private function buildCandidates(string $name, string $type): array
    {
        $stopWords = ['head', 'office', 'warehouse', 'branch', 'gudang', 'cabang', 'kantor', 'pusat'];
        $typeSuffix = match ($type) {
            'head_office' => 'HO',
            'warehouse' => 'WH',
            'branch_office' => 'BR',
            default => '',
        };

        $words = preg_split('/[\s\-_]+/', strtoupper(trim($name))) ?: [];
        $words = array_values(array_filter(
            $words,
            fn (string $w) => $w !== '' && ! in_array(strtolower($w), $stopWords, true)
        ));

        $candidates = [];

        if ($type === 'head_office') {
            $candidates[] = 'HO';
        }

        if ($words !== []) {
            $first = preg_replace('/[^A-Z]/', '', $words[0]) ?? '';
            if (strlen($first) >= 3) {
                $candidates[] = substr($first, 0, 3);
            } elseif (strlen($first) === 2) {
                $candidates[] = $first;
            }

            if ($typeSuffix !== '' && $first !== '') {
                $candidates[] = substr($first, 0, 1).$typeSuffix;
            }
        }

        $initials = '';
        foreach ($words as $word) {
            $clean = preg_replace('/[^A-Z]/', '', $word) ?? '';
            if ($clean !== '') {
                $initials .= $clean[0];
            }
            if (strlen($initials) >= 3) {
                break;
            }
        }
        if (strlen($initials) >= 2) {
            $candidates[] = substr($initials, 0, 3);
        }

        if ($typeSuffix !== '') {
            $candidates[] = $typeSuffix;
        }

        $unique = [];
        foreach ($candidates as $code) {
            $code = strtoupper(preg_replace('/[^A-Z]/', '', $code) ?? '');
            if (strlen($code) >= 2 && strlen($code) <= 3) {
                $unique[$code] = $code;
            }
        }

        return array_values($unique);
    }

    private function exists(int $companyId, string $code): bool
    {
        return CustomerLocation::where('company_id', $companyId)
            ->where('code', $code)
            ->exists();
    }
}
