<?php

namespace Database\Seeders;

use App\Models\SystemSetting;
use App\Support\SystemSettingsSchema;
use Illuminate\Database\Seeder;

class SystemSettingsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (SystemSettingsSchema::fields() as $field) {
            SystemSetting::query()->firstOrCreate(
                ['key' => $field['key']],
                [
                    'value' => $field['default'] ?? null,
                    'group' => $field['group'],
                ]
            );
        }
    }
}
