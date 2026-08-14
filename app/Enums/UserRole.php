<?php

declare(strict_types=1);

namespace App\Enums;

enum UserRole: string
{
    // Internal roles
    case SuperAdmin = 'super_admin';
    case Operations = 'operations';
    case Finance = 'finance';
    case Sales = 'sales';
    case CustomerService = 'customer_service';
    case Billing = 'billing';
    case AccountManager = 'account_manager';
    case Management = 'management';
    case InternalViewer = 'internal_viewer';

    // Customer roles
    case CompanyAdmin = 'company_admin';
    case OpsPic = 'ops_pic';
    case FinancePic = 'finance_pic';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::Operations => 'Operations',
            self::Finance => 'Finance',
            self::Sales => 'Sales',
            self::CustomerService => 'Customer Service',
            self::Billing => 'Billing',
            self::AccountManager => 'Account Manager',
            self::Management => 'Management',
            self::InternalViewer => 'Viewer',
            self::CompanyAdmin => 'Company Admin',
            self::OpsPic => 'Operational PIC',
            self::FinancePic => 'Finance PIC',
            self::Viewer => 'Viewer',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::CompanyAdmin => 'bg-indigo-100 text-indigo-700 border-indigo-200',
            self::OpsPic => 'bg-blue-100 text-blue-700 border-blue-200',
            self::FinancePic => 'bg-emerald-100 text-emerald-700 border-emerald-200',
            self::Viewer, self::InternalViewer => 'bg-neutral-100 text-neutral-700 border-neutral-200',
            default => 'bg-slate-100 text-slate-700 border-slate-200',
        };
    }

    /**
     * Default feature_access permissions per role (FSD Users → Default Role).
     *
     * @return list<string>
     */
    public function defaultFeatureAccess(): array
    {
        return match ($this) {
            self::SuperAdmin => [
                'view_companies', 'create_companies', 'edit_companies', 'delete_companies', 'approve_companies',
                'view_users', 'create_users', 'edit_users', 'delete_users',
                'manage_master_data',
                'view_bookings', 'create_bookings', 'edit_bookings', 'approve_bookings', 'reject_bookings', 'cancel_bookings',
                'view_shipments', 'create_shipments', 'edit_shipments', 'update_tracking',
                'view_invoices', 'create_invoices', 'edit_invoices', 'approve_invoices', 'void_invoices',
                'view_payments', 'manage_payments',
                'manage_vendors', 'manage_pricing', 'manage_discounts',
                'view_reports', 'export_reports', 'view_dashboard', 'view_analytics',
                'manage_branches', 'manage_documents', 'view_documents', 'manage_notifications',
                'view_audit_log', 'view_vendors', 'view_pricing', 'edit_pricing',
                'view_containers', 'edit_containers', 'manage_containers', 'manage_tracking_photos',
                'view_operations', 'manage_operations',
                'view_vendor_job_orders_admin', 'manage_vendor_job_orders_admin',
                'view_vendor_invoices_admin', 'manage_vendor_invoices_admin',
                'view_vendor_payments_admin', 'manage_vendor_payments_admin',
                'manage_roles', 'view_settings', 'manage_settings', 'manage_numbering', 'manage_system_config',
            ],
            self::Operations => [
                'view_companies', 'view_bookings', 'approve_bookings', 'reject_bookings',
                'view_shipments', 'create_shipments', 'edit_shipments', 'update_tracking',
                'view_invoices', 'view_dashboard',
                'view_containers', 'edit_containers', 'manage_containers',
                'view_operations', 'manage_operations',
                'view_vendor_job_orders_admin',
                'view_reports', 'export_reports',
                'manage_master_data',
            ],
            self::Finance => [
                'view_companies', 'view_bookings', 'view_shipments',
                'view_invoices', 'create_invoices', 'edit_invoices',
                'view_payments', 'manage_payments',
                'view_dashboard',
                'view_vendor_invoices_admin', 'manage_vendor_invoices_admin',
                'view_vendor_payments_admin', 'manage_vendor_payments_admin',
                'view_reports', 'export_reports',
            ],
            self::Sales => [
                'view_companies', 'create_companies', 'edit_companies',
                'view_bookings', 'view_shipments', 'view_invoices',
                'manage_pricing', 'manage_vendors', 'view_pricing', 'edit_pricing',
                'view_dashboard', 'manage_master_data',
            ],
            self::CustomerService => [
                'view_companies', 'view_bookings', 'create_bookings', 'edit_bookings',
                'view_shipments', 'view_invoices', 'view_payments',
                'view_dashboard', 'view_documents',
            ],
            self::Billing => [
                'view_companies', 'view_bookings', 'view_shipments',
                'view_invoices', 'create_invoices', 'edit_invoices', 'approve_invoices',
                'view_payments', 'manage_payments',
                'view_dashboard', 'view_reports', 'export_reports',
            ],
            self::AccountManager => [
                'view_companies', 'create_companies', 'edit_companies',
                'view_bookings', 'view_shipments', 'view_invoices', 'view_payments',
                'view_dashboard', 'view_reports',
            ],
            self::Management => [
                'view_companies', 'view_bookings', 'view_shipments',
                'view_invoices', 'view_payments',
                'view_dashboard', 'view_analytics', 'view_reports', 'export_reports',
            ],
            self::InternalViewer => [
                'view_companies', 'view_bookings', 'view_shipments',
                'view_invoices', 'view_payments',
                'view_dashboard', 'view_reports',
            ],
            self::CompanyAdmin => [
                'view_company', 'manage_company',
                'view_locations', 'manage_locations',
                'view_users', 'create_users', 'edit_users',
                'view_bookings', 'create_bookings', 'manage_bookings',
                'view_shipments',
                'view_invoices', 'view_payments',
                'view_documents', 'manage_documents',
            ],
            self::OpsPic => [
                'view_company',
                'view_locations',
                'view_bookings', 'create_bookings',
                'view_shipments',
            ],
            self::FinancePic => [
                'view_company',
                'view_invoices', 'view_payments',
                'view_documents',
            ],
            self::Viewer => [
                'view_company',
                'view_locations',
                'view_users',
                'view_bookings',
                'view_shipments',
                'view_invoices', 'view_payments',
                'view_documents',
            ],
        };
    }
}
