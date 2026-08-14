<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NumberingFormat extends Model
{
    protected $fillable = [
        'document_type',
        'prefix',
        'running_digits',
        'separator',
        'reset_period',
        'last_number',
        'last_reset_at',
    ];

    protected function casts(): array
    {
        return [
            'last_reset_at' => 'datetime',
        ];
    }

    public function preview(): string
    {
        return self::previewFrom([
            'prefix' => $this->prefix,
            'running_digits' => $this->running_digits,
            'separator' => $this->separator,
            'reset_period' => $this->reset_period,
        ]);
    }

    /** @param array<string, mixed> $attrs */
    public static function previewFrom(array $attrs): string
    {
        $period = match ($attrs['reset_period'] ?? 'never') {
            'monthly' => now()->format('Ym'),
            'yearly' => now()->format('Y'),
            default => '',
        };

        $digits = max(1, (int) ($attrs['running_digits'] ?? 5));
        $number = str_pad('1', $digits, '0', STR_PAD_LEFT);
        $separator = $attrs['separator'] ?? '-';
        $sep = $separator === 'none' ? '' : ($separator ?: '-');
        $prefix = (string) ($attrs['prefix'] ?? '');

        if ($period === '') {
            return $prefix.$sep.$number;
        }

        return $prefix.$sep.$period.$sep.$number;
    }
}
