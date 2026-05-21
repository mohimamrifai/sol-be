<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // ── Permissions (25+; extended for granular roles) ──
        $permissions = [
            // Customer Management
            'view_companies', 'create_companies', 'edit_companies', 'delete_companies', 'approve_companies',
            // User Management
            'view_users', 'create_users', 'edit_users', 'delete_users',
            // Master Data
            'manage_master_data',
            // Booking
            'view_bookings', 'create_bookings', 'edit_bookings', 'approve_bookings', 'reject_bookings', 'cancel_bookings',
            // Shipment
            'view_shipments', 'create_shipments', 'edit_shipments', 'update_tracking',
            // Invoice
            'view_invoices', 'create_invoices', 'edit_invoices', 'approve_invoices', 'void_invoices',
            // Payment
            'view_payments', 'manage_payments',
            // Vendor & Pricing
            'manage_vendors', 'manage_pricing', 'manage_discounts',
            // Reporting & ops
            'view_reports', 'export_reports', 'view_dashboard', 'view_analytics',
            'manage_branches', 'manage_documents', 'manage_notifications',
            'view_audit_log', 'view_vendors', 'view_pricing', 'edit_pricing',
            'view_containers', 'edit_containers', 'manage_tracking_photos',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        // ── Roles Internal ──
        $superAdmin = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $superAdmin->givePermissionTo(Permission::all());

        $operations = Role::firstOrCreate(['name' => 'operations', 'guard_name' => 'web']);
        $operations->givePermissionTo([
            'view_companies', 'view_bookings', 'approve_bookings', 'reject_bookings',
            'view_shipments', 'create_shipments', 'edit_shipments', 'update_tracking',
            'view_invoices',
        ]);

        $finance = Role::firstOrCreate(['name' => 'finance', 'guard_name' => 'web']);
        $finance->givePermissionTo([
            'view_companies', 'view_bookings', 'view_shipments',
            'view_invoices', 'create_invoices', 'edit_invoices',
            'view_payments', 'manage_payments',
        ]);

        $sales = Role::firstOrCreate(['name' => 'sales', 'guard_name' => 'web']);
        $sales->givePermissionTo([
            'view_companies', 'create_companies', 'edit_companies',
            'view_bookings', 'view_shipments', 'view_invoices',
            'manage_pricing',
        ]);

        // ── Roles Customer ──
        $companyAdmin = Role::firstOrCreate(['name' => 'company_admin', 'guard_name' => 'web']);
        $companyAdmin->givePermissionTo([
            'view_bookings', 'create_bookings',
            'view_shipments', 'view_invoices', 'view_payments',
            'view_users', 'create_users', 'edit_users',
        ]);

        $opsPic = Role::firstOrCreate(['name' => 'ops_pic', 'guard_name' => 'web']);
        $opsPic->givePermissionTo([
            'view_bookings', 'create_bookings', 'view_shipments',
        ]);

        $financePic = Role::firstOrCreate(['name' => 'finance_pic', 'guard_name' => 'web']);
        $financePic->givePermissionTo([
            'view_bookings', 'view_shipments', 'view_invoices', 'view_payments',
        ]);

        // ── Super Admin User (login dev: admin@sol.com / password) ──
        // Pakai updateOrCreate agar password ikut di-reset saat seed dijalankan ulang;
        // firstOrCreate tidak memperbarui baris yang sudah ada sehingga hash password bisa tidak sesuai seed.
        $admin = User::updateOrCreate(
            ['email' => 'admin@sol.com'],
            [
                'name' => 'Super Admin',
                'password' => bcrypt('password'),
                'phone' => '081234567890',
                'status' => 'active',
                'user_type' => 'internal',
            ]
        );
        $admin->assignRole('super_admin');
    }
}
