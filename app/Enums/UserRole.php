<?php

declare(strict_types=1);

namespace App\Enums;

enum UserRole: string
{
    case CompanyAdmin = 'company_admin';
    case OpsPic = 'ops_pic';
    case FinancePic = 'finance_pic';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
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
            self::Viewer => 'bg-neutral-100 text-neutral-700 border-neutral-200',
        };
    }

    /**
     * Default feature_access permissions per role (FSD Users → Default Role).
     */
    public function defaultFeatureAccess(): array
    {
        return match ($this) {
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
