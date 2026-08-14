<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

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
            'manage_branches', 'manage_documents', 'view_documents', 'manage_notifications',
            'view_audit_log', 'view_vendors', 'view_pricing', 'edit_pricing',
            'view_containers', 'edit_containers', 'manage_containers', 'manage_tracking_photos',
            // Operations (FSD)
            'view_operations', 'manage_operations',
            // Vendor admin (FSD)
            'view_vendor_job_orders_admin', 'manage_vendor_job_orders_admin',
            'view_vendor_invoices_admin', 'manage_vendor_invoices_admin',
            'view_vendor_payments_admin', 'manage_vendor_payments_admin',
            // Settings (FSD)
            'manage_roles', 'view_settings', 'manage_settings',
            'manage_numbering', 'manage_system_config',
            // Granular customer permissions (FSD Users → Default Role)
            'view_company', 'manage_company',
            'view_locations', 'manage_locations',
            'manage_bookings',
            // Vendor Portal
            'vendor.access', 'vendor.dashboard',
            'view_vendor_job_orders', 'manage_vendor_job_orders',
            'accept_job_orders', 'submit_progress',
            'view_vendor_documents', 'view_vendor_invoices',
            'manage_vendor_invoices', 'submit_vendor_invoices',
            'view_vendor_payments', 'view_vendor_company',
            'manage_vendor_company', 'view_vendor_users',
            'manage_vendor_users', 'manage_vendor_profile',
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
            'view_invoices', 'view_dashboard',
            'view_containers', 'edit_containers', 'manage_containers',
            'view_operations', 'manage_operations',
            'view_vendor_job_orders_admin',
            'view_reports', 'export_reports',
            'manage_master_data',
        ]);

        $finance = Role::firstOrCreate(['name' => 'finance', 'guard_name' => 'web']);
        $finance->givePermissionTo([
            'view_companies', 'view_bookings', 'view_shipments',
            'view_invoices', 'create_invoices', 'edit_invoices',
            'view_payments', 'manage_payments',
            'view_dashboard',
            'view_vendor_invoices_admin', 'manage_vendor_invoices_admin',
            'view_vendor_payments_admin', 'manage_vendor_payments_admin',
            'view_reports', 'export_reports',
        ]);

        $sales = Role::firstOrCreate(['name' => 'sales', 'guard_name' => 'web']);
        $sales->givePermissionTo([
            'view_companies', 'create_companies', 'edit_companies',
            'view_bookings', 'view_shipments', 'view_invoices',
            'manage_pricing', 'manage_vendors', 'view_pricing', 'edit_pricing',
            'view_dashboard', 'manage_master_data',
        ]);

        $customerService = Role::firstOrCreate(['name' => 'customer_service', 'guard_name' => 'web']);
        $customerService->givePermissionTo([
            'view_companies', 'view_bookings', 'create_bookings', 'edit_bookings',
            'view_shipments', 'view_invoices', 'view_payments',
            'view_dashboard', 'view_documents',
        ]);

        $billing = Role::firstOrCreate(['name' => 'billing', 'guard_name' => 'web']);
        $billing->givePermissionTo([
            'view_companies', 'view_bookings', 'view_shipments',
            'view_invoices', 'create_invoices', 'edit_invoices', 'approve_invoices',
            'view_payments', 'manage_payments',
            'view_dashboard', 'view_reports', 'export_reports',
        ]);

        $accountManager = Role::firstOrCreate(['name' => 'account_manager', 'guard_name' => 'web']);
        $accountManager->givePermissionTo([
            'view_companies', 'create_companies', 'edit_companies',
            'view_bookings', 'view_shipments', 'view_invoices', 'view_payments',
            'view_dashboard', 'view_reports',
        ]);

        $management = Role::firstOrCreate(['name' => 'management', 'guard_name' => 'web']);
        $management->givePermissionTo([
            'view_companies', 'view_bookings', 'view_shipments',
            'view_invoices', 'view_payments',
            'view_dashboard', 'view_analytics', 'view_reports', 'export_reports',
        ]);

        $internalViewer = Role::firstOrCreate(['name' => 'internal_viewer', 'guard_name' => 'web']);
        $internalViewer->givePermissionTo([
            'view_companies', 'view_bookings', 'view_shipments',
            'view_invoices', 'view_payments',
            'view_dashboard', 'view_reports',
        ]);

        // ── Roles Customer ──
        $companyAdmin = Role::firstOrCreate(['name' => 'company_admin', 'guard_name' => 'web']);
        $companyAdmin->givePermissionTo([
            'view_company', 'manage_company',
            'view_locations', 'manage_locations',
            'view_users', 'create_users', 'edit_users',
            'view_bookings', 'create_bookings', 'manage_bookings',
            'view_shipments', 'view_invoices', 'view_payments',
            'view_documents', 'manage_documents',
        ]);

        $opsPic = Role::firstOrCreate(['name' => 'ops_pic', 'guard_name' => 'web']);
        $opsPic->givePermissionTo([
            'view_company', 'view_locations',
            'view_bookings', 'create_bookings',
            'view_shipments', 'view_documents',
        ]);

        $financePic = Role::firstOrCreate(['name' => 'finance_pic', 'guard_name' => 'web']);
        $financePic->givePermissionTo([
            'view_company',
            'view_bookings', 'view_shipments',
            'view_invoices', 'view_payments',
            'view_documents', 'manage_documents',
        ]);

        $viewer = Role::firstOrCreate(['name' => 'viewer', 'guard_name' => 'web']);
        $viewer->givePermissionTo([
            'view_company',
            'view_locations',
            'view_users',
            'view_bookings',
            'view_shipments',
            'view_invoices', 'view_payments',
            'view_documents',
        ]);

        // ── Roles Vendor ──
        $vendorCompanyAdmin = Role::firstOrCreate(['name' => 'vendor_company_admin', 'guard_name' => 'web']);
        $vendorCompanyAdmin->givePermissionTo([
            'vendor.access', 'vendor.dashboard',
            'view_vendor_job_orders', 'manage_vendor_job_orders',
            'accept_job_orders', 'submit_progress',
            'view_vendor_documents',
            'view_vendor_invoices', 'manage_vendor_invoices', 'submit_vendor_invoices',
            'view_vendor_payments',
            'view_vendor_company', 'manage_vendor_company',
            'view_vendor_users', 'manage_vendor_users',
            'manage_vendor_profile',
        ]);

        $vendorOpsPic = Role::firstOrCreate(['name' => 'vendor_ops_pic', 'guard_name' => 'web']);
        $vendorOpsPic->givePermissionTo([
            'vendor.access', 'vendor.dashboard',
            'view_vendor_job_orders', 'manage_vendor_job_orders',
            'accept_job_orders', 'submit_progress',
            'view_vendor_documents',
            'view_vendor_invoices',
            'view_vendor_company',
            'manage_vendor_profile',
        ]);

        $vendorFinancePic = Role::firstOrCreate(['name' => 'vendor_finance_pic', 'guard_name' => 'web']);
        $vendorFinancePic->givePermissionTo([
            'vendor.access', 'vendor.dashboard',
            'view_vendor_job_orders',
            'view_vendor_documents',
            'view_vendor_invoices', 'manage_vendor_invoices', 'submit_vendor_invoices',
            'view_vendor_payments',
            'view_vendor_company',
            'manage_vendor_profile',
        ]);

        $vendorViewer = Role::firstOrCreate(['name' => 'vendor_viewer', 'guard_name' => 'web']);
        $vendorViewer->givePermissionTo([
            'vendor.access', 'vendor.dashboard',
            'view_vendor_job_orders',
            'view_vendor_documents',
            'view_vendor_invoices',
            'view_vendor_payments',
            'view_vendor_company',
            'manage_vendor_profile',
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
        $admin->syncRoles(['super_admin']);
        $admin->update(['feature_access' => UserRole::SuperAdmin->defaultFeatureAccess()]);
    }
}
