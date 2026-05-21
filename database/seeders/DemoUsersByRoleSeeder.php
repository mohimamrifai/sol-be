<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * User demo per role sesuai MVP brief / dokumen.md:
 * - Internal: Operations, Finance, Sales (Admin = super_admin → admin@sol.com di RolesAndPermissionsSeeder).
 * - Customer: Company Admin, Operations PIC, Finance PIC.
 *
 * Password semua: "password".
 *
 * Catatan: RolesAndPermissionsSeeder juga mendefinisikan role granular tambahan (warehouse_staff, dll.)
 * untuk RBAC ke depan; role itu tidak wajib punya user demo di sini karena di luar daftar role MVP brief.
 */
class DemoUsersByRoleSeeder extends Seeder
{
    private const PASSWORD_PLAIN = 'password';

    /** Role internal MVP (brief: Operations, Finance, Sales). Admin = super_admin terpisah. */
    private function internalMvpRoleNames(): array
    {
        return ['operations', 'finance', 'sales'];
    }

    public function run(): void
    {
        foreach ($this->internalMvpRoleNames() as $roleName) {
            $slug = str_replace('_', '-', $roleName);
            $email = "{$slug}@demo.internal.sol.test";
            $label = ucwords(str_replace('_', ' ', $roleName));

            $user = User::updateOrCreate(
                ['email' => $email],
                [
                    'name' => "Demo {$label}",
                    'password' => bcrypt(self::PASSWORD_PLAIN),
                    'phone' => sprintf('0812%07d', abs(crc32($roleName)) % 10000000),
                    'status' => 'active',
                    'user_type' => 'internal',
                    'company_id' => null,
                ]
            );
            $user->syncRoles([$roleName]);
        }

        $company = Company::query()->orderBy('id')->first();
        if (! $company) {
            $this->command?->warn('DemoUsersByRoleSeeder: tidak ada company — lewati user customer (jalankan BulkTransactionalSeeder dulu).');

            return;
        }

        $customerDemos = [
            ['email' => 'company.admin.demo@demo.customer.sol.test', 'name' => 'Demo Company Admin', 'role' => 'company_admin'],
            ['email' => 'ops.pic.demo@demo.customer.sol.test', 'name' => 'Demo Ops PIC', 'role' => 'ops_pic'],
            ['email' => 'finance.pic.demo@demo.customer.sol.test', 'name' => 'Demo Finance PIC', 'role' => 'finance_pic'],
        ];

        foreach ($customerDemos as $row) {
            $user = User::updateOrCreate(
                ['email' => $row['email']],
                [
                    'name' => $row['name'],
                    'password' => bcrypt(self::PASSWORD_PLAIN),
                    'phone' => sprintf('0813%07d', abs(crc32($row['email'])) % 10000000),
                    'status' => 'active',
                    'user_type' => 'customer',
                    'company_id' => $company->id,
                ]
            );
            $user->syncRoles([$row['role']]);
        }
    }
}
