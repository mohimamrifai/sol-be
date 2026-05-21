<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class OperationalDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Cargo Categories
        $categories = [
            ['code' => 'GEN', 'name' => 'General Cargo', 'description' => 'Barang umum tanpa penanganan khusus', 'flags' => []],
            ['code' => 'DRY', 'name' => 'Dry Food', 'description' => 'Makanan kering tanpa kebutuhan suhu', 'flags' => ['is_food' => true]],
            ['code' => 'REF', 'name' => 'Perishable', 'description' => 'Barang dengan suhu terkontrol', 'flags' => ['requires_temperature' => true, 'is_food' => true]],
            ['code' => 'PRJ', 'name' => 'Project Cargo', 'description' => 'Barang besar/berat atau over dimension', 'flags' => ['is_project_cargo' => true]],
            ['code' => 'LIQ', 'name' => 'Liquid / Bulk', 'description' => 'Barang cair atau curah', 'flags' => ['is_liquid' => true]],
            ['code' => 'MIX', 'name' => 'Mixed Cargo', 'description' => 'Kargo campuran dalam satu shipment', 'flags' => []],
            ['code' => 'DG', 'name' => 'Dangerous Goods', 'description' => 'Barang berbahaya (DG) yang membutuhkan penanganan khusus', 'flags' => []],
        ];

        foreach ($categories as $cat) {
            \App\Models\CargoCategory::updateOrCreate(
                ['code' => $cat['code']],
                array_merge([
                    'name' => $cat['name'],
                    'description' => $cat['description'],
                    'is_active' => true
                ], $cat['flags'])
            );
        }

        // 2. DG Classes
        $dgClasses = [
            ['code' => '1', 'name' => 'Class 1: Explosives'],
            ['code' => '2', 'name' => 'Class 2: Gases'],
            ['code' => '3', 'name' => 'Class 3: Flammable Liquids'],
            ['code' => '4', 'name' => 'Class 4: Flammable Solids'],
            ['code' => '5', 'name' => 'Class 5: Oxidizing Substances'],
            ['code' => '6', 'name' => 'Class 6: Toxic & Infectious'],
            ['code' => '7', 'name' => 'Class 7: Radioactive Material'],
            ['code' => '8', 'name' => 'Class 8: Corrosives'],
            ['code' => '9', 'name' => 'Class 9: Miscellaneous Dangerous Goods'],
        ];

        foreach ($dgClasses as $dg) {
            \App\Models\DgClass::updateOrCreate(
                ['code' => $dg['code']],
                ['name' => $dg['name'], 'is_active' => true]
            );
        }

        // 3. Additional Charges
        $charges = [
            ['code' => 'DG', 'name' => 'Dangerous Goods Handling', 'description' => 'Biaya penanganan barang berbahaya'],
            ['code' => 'REF', 'name' => 'Reefer Charge', 'description' => 'Biaya pendingin'],
            ['code' => 'OOG', 'name' => 'Out of Gauge', 'description' => 'Biaya oversize'],
            ['code' => 'LIQ', 'name' => 'Liquid Handling', 'description' => 'Biaya penanganan cair'],
            ['code' => 'MIX', 'name' => 'Consolidation Handling', 'description' => 'Biaya konsolidasi'],
            ['code' => 'CLEAN', 'name' => 'Cleaning Required', 'description' => 'Biaya pembersihan'],
        ];

        foreach ($charges as $ch) {
            \App\Models\AdditionalCharge::updateOrCreate(
                ['code' => $ch['code']],
                ['name' => $ch['name'], 'description' => $ch['description'], 'is_active' => true]
            );
        }
    }
}
