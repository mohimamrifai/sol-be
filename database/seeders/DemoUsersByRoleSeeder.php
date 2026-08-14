<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoUsersByRoleSeeder extends Seeder
{
    private const PASSWORD = 'password';

    /**
     * Internal demo users for admin dashboard role testing (see info.md).
     *
     * @var list<array{email: string, name: string, role: UserRole}>
     */
    private const INTERNAL_DEMO_USERS = [
        [
            'email' => 'operations@demo.internal.sol.test',
            'name' => 'Demo Operations',
            'role' => UserRole::Operations,
        ],
        [
            'email' => 'finance@demo.internal.sol.test',
            'name' => 'Demo Finance',
            'role' => UserRole::Finance,
        ],
        [
            'email' => 'sales@demo.internal.sol.test',
            'name' => 'Demo Sales',
            'role' => UserRole::Sales,
        ],
        [
            'email' => 'cs@demo.internal.sol.test',
            'name' => 'Demo Customer Service',
            'role' => UserRole::CustomerService,
        ],
        [
            'email' => 'billing@demo.internal.sol.test',
            'name' => 'Demo Billing',
            'role' => UserRole::Billing,
        ],
        [
            'email' => 'am@demo.internal.sol.test',
            'name' => 'Demo Account Manager',
            'role' => UserRole::AccountManager,
        ],
        [
            'email' => 'management@demo.internal.sol.test',
            'name' => 'Demo Management',
            'role' => UserRole::Management,
        ],
        [
            'email' => 'viewer@demo.internal.sol.test',
            'name' => 'Demo Internal Viewer',
            'role' => UserRole::InternalViewer,
        ],
    ];

    public function run(): void
    {
        foreach (self::INTERNAL_DEMO_USERS as $row) {
            $user = User::updateOrCreate(
                ['email' => $row['email']],
                [
                    'name' => $row['name'],
                    'password' => bcrypt(self::PASSWORD),
                    'phone' => null,
                    'status' => 'active',
                    'user_type' => 'internal',
                    'feature_access' => $row['role']->defaultFeatureAccess(),
                ]
            );
            $user->syncRoles([$row['role']->value]);
        }
    }
}
