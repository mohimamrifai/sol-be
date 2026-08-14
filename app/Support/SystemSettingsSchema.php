<?php

declare(strict_types=1);

namespace App\Support;

class SystemSettingsSchema
{
    /** @return array<int, array<string, mixed>> */
    public static function fields(): array
    {
        return [
            ['key' => 'system_name', 'group' => 'general', 'type' => 'text', 'label' => 'System Name', 'default' => 'SOL Logistics'],
            ['key' => 'default_currency', 'group' => 'general', 'type' => 'select', 'label' => 'Default Currency', 'default' => 'IDR', 'options' => ['IDR']],
            ['key' => 'timezone', 'group' => 'general', 'type' => 'select', 'label' => 'Time Zone', 'default' => 'Asia/Jakarta', 'options' => ['Asia/Jakarta', 'Asia/Singapore', 'UTC']],
            ['key' => 'date_format', 'group' => 'general', 'type' => 'select', 'label' => 'Date Format', 'default' => 'dd/MM/yyyy', 'options' => ['dd/MM/yyyy', 'yyyy-MM-dd']],
            ['key' => 'language', 'group' => 'general', 'type' => 'select', 'label' => 'Language', 'default' => 'id', 'options' => ['id' => 'Indonesia', 'en' => 'English']],

            ['key' => 'booking_expired_hours', 'group' => 'booking', 'type' => 'number', 'label' => 'Booking Expired (Hours)', 'default' => 48],
            ['key' => 'free_storage_lcl_origin_days', 'group' => 'booking', 'type' => 'number', 'label' => 'Default Free Storage LCL Origin (Days)', 'default' => 3],
            ['key' => 'free_storage_lcl_destination_days', 'group' => 'booking', 'type' => 'number', 'label' => 'Default Free Storage LCL Destination (Days)', 'default' => 3],
            ['key' => 'free_storage_fcl_origin_days', 'group' => 'booking', 'type' => 'number', 'label' => 'Default Free Storage FCL Origin (Days)', 'default' => 5],
            ['key' => 'free_storage_fcl_destination_days', 'group' => 'booking', 'type' => 'number', 'label' => 'Default Free Storage FCL Destination (Days)', 'default' => 5],
            ['key' => 'allow_over_capacity', 'group' => 'booking', 'type' => 'boolean', 'label' => 'Allow Over Capacity', 'default' => false],

            ['key' => 'default_tax_rate', 'group' => 'finance', 'type' => 'number', 'label' => 'Default Tax Rate (%)', 'default' => 11],
            ['key' => 'invoice_due_reminder_days', 'group' => 'finance', 'type' => 'number', 'label' => 'Invoice Due Reminder (Days)', 'default' => 3],
            ['key' => 'default_payment_term', 'group' => 'finance', 'type' => 'select', 'label' => 'Default Payment Term', 'default' => '30', 'options' => ['COD', '7', '14', '30', '45']],

            ['key' => 'midtrans_environment', 'group' => 'integration', 'type' => 'select', 'label' => 'Midtrans Environment', 'default' => 'sandbox', 'options' => ['sandbox', 'production']],
            ['key' => 'midtrans_server_key', 'group' => 'integration', 'type' => 'password', 'label' => 'Midtrans Server Key', 'default' => '', 'secret' => true],
            ['key' => 'midtrans_client_key', 'group' => 'integration', 'type' => 'password', 'label' => 'Midtrans Client Key', 'default' => '', 'secret' => true],
            ['key' => 'alibaba_oss_endpoint', 'group' => 'integration', 'type' => 'text', 'label' => 'Alibaba OSS Endpoint', 'default' => ''],
            ['key' => 'alibaba_oss_bucket', 'group' => 'integration', 'type' => 'text', 'label' => 'Alibaba OSS Bucket', 'default' => ''],
            ['key' => 'alibaba_oss_access_key', 'group' => 'integration', 'type' => 'password', 'label' => 'Alibaba OSS Access Key', 'default' => '', 'secret' => true],
            ['key' => 'alibaba_oss_secret_key', 'group' => 'integration', 'type' => 'password', 'label' => 'Alibaba OSS Secret Key', 'default' => '', 'secret' => true],

            ['key' => 'smtp_host', 'group' => 'email', 'type' => 'text', 'label' => 'SMTP Host', 'default' => ''],
            ['key' => 'smtp_port', 'group' => 'email', 'type' => 'number', 'label' => 'SMTP Port', 'default' => 587],
            ['key' => 'smtp_username', 'group' => 'email', 'type' => 'text', 'label' => 'SMTP Username', 'default' => ''],
            ['key' => 'smtp_password', 'group' => 'email', 'type' => 'password', 'label' => 'SMTP Password', 'default' => '', 'secret' => true],
            ['key' => 'sender_name', 'group' => 'email', 'type' => 'text', 'label' => 'Sender Name', 'default' => 'SOL Logistics'],
            ['key' => 'sender_email', 'group' => 'email', 'type' => 'email', 'label' => 'Sender Email', 'default' => ''],
        ];
    }

    public static function isSecret(string $key): bool
    {
        foreach (self::fields() as $field) {
            if ($field['key'] === $key) {
                return (bool) ($field['secret'] ?? false);
            }
        }

        return false;
    }

    /** @return array<string, mixed>|null */
    public static function find(string $key): ?array
    {
        foreach (self::fields() as $field) {
            if ($field['key'] === $key) {
                return $field;
            }
        }

        return null;
    }
}
