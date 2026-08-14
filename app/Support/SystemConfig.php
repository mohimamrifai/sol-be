<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\SystemSetting;
use Illuminate\Support\Carbon;

class SystemConfig
{
    /** @var array<string, mixed> */
    private static array $memo = [];

    public static function flush(): void
    {
        self::$memo = [];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, self::$memo)) {
            return self::$memo[$key];
        }

        $field = SystemSettingsSchema::find($key);
        $fallback = $default ?? ($field['default'] ?? null);
        self::$memo[$key] = SystemSetting::getValue($key, $fallback);

        return self::$memo[$key];
    }

    public static function taxRatePercent(): float
    {
        return max(0.0, (float) self::get('default_tax_rate', 11));
    }

    public static function taxMultiplier(): float
    {
        return self::taxRatePercent() / 100;
    }

    public static function taxFactor(): float
    {
        return 1 + self::taxMultiplier();
    }

    /**
     * @return array{subtotal: float, tax_amount: float, total_amount: float}
     */
    public static function applyTax(float $subtotal): array
    {
        $subtotal = max(0.0, $subtotal);
        $taxAmount = round($subtotal * self::taxMultiplier(), 2);

        return [
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total_amount' => round($subtotal + $taxAmount, 2),
        ];
    }

    public static function taxLabel(): string
    {
        $rate = self::taxRatePercent();
        $formatted = rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');

        return "PPN ({$formatted}%)";
    }

    public static function bookingExpiredHours(): int
    {
        return max(1, (int) self::get('booking_expired_hours', 48));
    }

    public static function draftExpiresAt(): Carbon
    {
        return now()->addHours(self::bookingExpiredHours());
    }

    public static function allowOverCapacity(): bool
    {
        return (bool) self::get('allow_over_capacity', false);
    }

    public static function freeStorageDays(string $loadType, string $leg): int
    {
        $loadType = strtolower($loadType) === 'fcl' ? 'fcl' : 'lcl';
        $leg = strtolower($leg) === 'destination' ? 'destination' : 'origin';
        $key = "free_storage_{$loadType}_{$leg}_days";
        $default = $loadType === 'fcl' ? 5 : 3;

        return max(0, (int) self::get($key, $default));
    }

    public static function resolveLoadType(?string $serviceTypeCode): string
    {
        return str_contains(strtolower((string) $serviceTypeCode), 'fcl') ? 'fcl' : 'lcl';
    }

    /**
     * @return array{origin: int, destination: int}
     */
    public static function defaultFreeStorageDays(?string $serviceTypeCode): array
    {
        $loadType = self::resolveLoadType($serviceTypeCode);

        return [
            'origin' => self::freeStorageDays($loadType, 'origin'),
            'destination' => self::freeStorageDays($loadType, 'destination'),
        ];
    }

    public static function defaultPaymentTerm(): string
    {
        $term = (string) self::get('default_payment_term', '30');

        return match ($term) {
            'COD' => 'cod',
            '7' => 'net_7',
            '14' => 'net_14',
            '30' => 'net_30',
            '45' => 'net_45',
            default => 'net_30',
        };
    }

    public static function defaultPostpaidTermDays(): int
    {
        $term = (string) self::get('default_payment_term', '30');
        if ($term === 'COD') {
            return 0;
        }

        return max(0, (int) $term);
    }

    public static function invoiceDueReminderDays(): int
    {
        return max(0, (int) self::get('invoice_due_reminder_days', 3));
    }

    public static function applyMidtransConfig(): void
    {
        $serverKey = trim((string) (self::get('midtrans_server_key') ?? ''));
        if ($serverKey === '') {
            return;
        }

        $clientKey = trim((string) (self::get('midtrans_client_key') ?? ''));
        $isProduction = (string) self::get('midtrans_environment', 'sandbox') === 'production';

        config([
            'midtrans.server_key' => $serverKey,
            'midtrans.client_key' => $clientKey !== '' ? $clientKey : config('midtrans.client_key'),
            'midtrans.is_production' => $isProduction,
            'midtrans.snap_url' => $isProduction
                ? 'https://app.midtrans.com/snap/v1/transactions'
                : 'https://app.sandbox.midtrans.com/snap/v1/transactions',
            'midtrans.api_base_url' => $isProduction
                ? 'https://api.midtrans.com/v2'
                : 'https://api.sandbox.midtrans.com/v2',
        ]);
    }

    public static function configureOssDisk(): ?string
    {
        $endpoint = trim((string) (self::get('alibaba_oss_endpoint') ?? ''));
        $bucket = trim((string) (self::get('alibaba_oss_bucket') ?? ''));
        $accessKey = trim((string) (self::get('alibaba_oss_access_key') ?? ''));
        $secretKey = trim((string) (self::get('alibaba_oss_secret_key') ?? ''));

        if ($endpoint === '' || $bucket === '' || $accessKey === '' || $secretKey === '') {
            return null;
        }

        config([
            'filesystems.disks.alibaba_oss' => [
                'driver' => 's3',
                'key' => $accessKey,
                'secret' => $secretKey,
                'region' => 'oss',
                'bucket' => $bucket,
                'endpoint' => $endpoint,
                'use_path_style_endpoint' => true,
                'throw' => false,
            ],
        ]);

        return 'alibaba_oss';
    }
}
