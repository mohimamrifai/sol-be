<?php

namespace Database\Seeders;

use App\Models\NumberingFormat;
use Illuminate\Database\Seeder;

class NumberingFormatSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            ['document_type' => 'BK', 'prefix' => 'BK'],
            ['document_type' => 'SHP', 'prefix' => 'SHP'],
            ['document_type' => 'CN', 'prefix' => 'CN'],
            ['document_type' => 'INV', 'prefix' => 'INV'],
            ['document_type' => 'PAY', 'prefix' => 'PAY'],
            ['document_type' => 'JO', 'prefix' => 'JO'],
            ['document_type' => 'VINV', 'prefix' => 'VINV'],
            ['document_type' => 'VPAY', 'prefix' => 'VPAY'],
        ];

        foreach ($defaults as $row) {
            NumberingFormat::query()->firstOrCreate(
                ['document_type' => $row['document_type']],
                [
                    'prefix' => $row['prefix'],
                    'running_digits' => 5,
                    'separator' => '-',
                    'reset_period' => 'monthly',
                    'last_number' => 0,
                ]
            );
        }
    }
}
