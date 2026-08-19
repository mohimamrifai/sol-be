<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NumberingFormat;
use Illuminate\Support\Facades\DB;

class DocumentNumberService
{
    public function generate(string $documentType): string
    {
        return DB::transaction(function () use ($documentType) {
            $format = NumberingFormat::query()
                ->where('document_type', $documentType)
                ->lockForUpdate()
                ->first();

            if (! $format) {
                return $this->fallback($documentType);
            }

            $this->resetCounterIfNeeded($format);

            $next = (int) $format->last_number + 1;
            $format->update(['last_number' => $next]);

            return $this->formatNumber($format, $next);
        });
    }

    private function resetCounterIfNeeded(NumberingFormat $format): void
    {
        $currentPeriod = match ($format->reset_period) {
            'monthly' => now()->format('Ym'),
            'yearly' => now()->format('Y'),
            default => null,
        };

        if ($currentPeriod === null) {
            return;
        }

        $lastPeriod = $format->last_reset_at?->format(
            $format->reset_period === 'yearly' ? 'Y' : 'Ym'
        );

        if ($lastPeriod !== $currentPeriod) {
            $format->update([
                'last_number' => 0,
                'last_reset_at' => now(),
            ]);
        }
    }

    private function formatNumber(NumberingFormat $format, int $number): string
    {
        $period = match ($format->reset_period) {
            'monthly' => now()->format('Ym'),
            'yearly' => now()->format('Y'),
            default => '',
        };

        $digits = max(1, (int) $format->running_digits);
        $running = str_pad((string) $number, $digits, '0', STR_PAD_LEFT);
        $separator = $format->separator ?? '-';
        $sep = $separator === 'none' ? '' : ($separator ?: '-');
        $prefix = (string) $format->prefix;

        if ($period === '') {
            return $prefix.$sep.$running;
        }

        return $prefix.$sep.$period.$sep.$running;
    }

    private function fallback(string $documentType): string
    {
        $prefix = match ($documentType) {
            'BK' => 'BK',
            'SHP' => 'SHP',
            'JO' => 'JO',
            'CN' => 'CN',
            'INV' => 'INV',
            'PAY' => 'PAY',
            'VINV' => 'VINV',
            'VPAY' => 'VPAY',
            default => $documentType,
        };

        return $prefix.'-'.now()->format('Ym').'-'.strtoupper(substr(uniqid(), -5));
    }
}
