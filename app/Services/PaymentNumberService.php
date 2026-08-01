<?php

namespace App\Services;

use App\Models\Payment;
use Illuminate\Support\Facades\DB;

/**
 * Meng-generate nomor urut pembayaran per company dengan format PAY000001.
 * Sequential (BIGINT), aman dari race condition via row lock + max().
 */
class PaymentNumberService
{
    public function next(int $companyId): int
    {
        return DB::transaction(function () use ($companyId) {
            $max = (int) Payment::query()
                ->whereHas('invoice', fn ($q) => $q->where('company_id', $companyId))
                ->lockForUpdate()
                ->max('payment_number');

            return $max + 1;
        });
    }
}
