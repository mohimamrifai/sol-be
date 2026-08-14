<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            DemoUsersByRoleSeeder::class,
            MasterDataSeeder::class,
            NumberingFormatSeeder::class,
            SystemSettingsSeeder::class,
            VendorSeeder::class,
            CustomerDemoSeeder::class,
        ]);
    }
}
