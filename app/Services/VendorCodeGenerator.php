<?php

namespace App\Services;

use App\Models\Vendor;

class VendorCodeGenerator
{
    public function generate(): string
    {
        $lastCode = Vendor::withTrashed()
            ->whereNotNull('code')
            ->where('code', 'like', 'VND%')
            ->orderByDesc('id')
            ->value('code');

        $next = 1;
        if ($lastCode && preg_match('/^VND(\d+)$/', $lastCode, $matches)) {
            $next = ((int) $matches[1]) + 1;
        }

        return 'VND'.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }
}
