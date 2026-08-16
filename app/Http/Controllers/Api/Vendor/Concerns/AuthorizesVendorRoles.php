<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Vendor\Concerns;

use App\Enums\VendorUserRole;
use Illuminate\Http\Request;

trait AuthorizesVendorRoles
{
    protected function authorizeNotViewer(Request $request): void
    {
        if ($request->user()->hasRole(VendorUserRole::VendorViewer->value)) {
            abort(response()->json(['message' => 'Anda tidak memiliki akses untuk aksi ini.'], 403));
        }
    }

    protected function authorizeCompanyAdmin(Request $request): void
    {
        if (! $request->user()->hasRole(VendorUserRole::VendorCompanyAdmin->value)) {
            abort(response()->json(['message' => 'Aksi ini hanya untuk Company Admin.'], 403));
        }
    }

    /** Job Order mutations: Company Admin + Operational PIC only. */
    protected function authorizeJobOrderWrite(Request $request): void
    {
        $user = $request->user();
        if ($user->hasRole(VendorUserRole::VendorViewer->value)) {
            abort(response()->json(['message' => 'Anda tidak memiliki akses untuk aksi ini.'], 403));
        }
        if ($user->hasRole(VendorUserRole::VendorFinancePic->value)
            && ! $user->hasRole(VendorUserRole::VendorCompanyAdmin->value)
            && ! $user->hasRole(VendorUserRole::VendorOpsPic->value)) {
            abort(response()->json(['message' => 'Finance PIC tidak dapat mengubah Job Order.'], 403));
        }
    }

    /** Invoice mutations: Company Admin + Finance PIC only. */
    protected function authorizeInvoiceWrite(Request $request): void
    {
        $user = $request->user();
        if ($user->hasRole(VendorUserRole::VendorViewer->value)) {
            abort(response()->json(['message' => 'Anda tidak memiliki akses untuk aksi ini.'], 403));
        }
        if ($user->hasRole(VendorUserRole::VendorOpsPic->value)
            && ! $user->hasRole(VendorUserRole::VendorCompanyAdmin->value)
            && ! $user->hasRole(VendorUserRole::VendorFinancePic->value)) {
            abort(response()->json(['message' => 'Operational PIC tidak dapat mengubah Vendor Invoice.'], 403));
        }
    }

    protected function userQuickActions(Request $request): array
    {
        $user = $request->user();
        $roles = $user->roles->pluck('name')->all();

        $canJobOrders = array_intersect($roles, [
            VendorUserRole::VendorCompanyAdmin->value,
            VendorUserRole::VendorOpsPic->value,
        ]) !== [];
        $canInvoices = array_intersect($roles, [
            VendorUserRole::VendorCompanyAdmin->value,
            VendorUserRole::VendorFinancePic->value,
        ]) !== [];

        return [
            'view_pending_jobs' => $canJobOrders,
            'create_invoice' => $canInvoices,
            'my_job_orders' => $canJobOrders,
        ];
    }
}
