<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Vendor;

use App\Enums\VendorInvoiceStatus;
use App\Enums\VendorJobStatus;
use App\Http\Controllers\Controller;
use App\Models\CompanyActivity;
use App\Models\Shipment;
use App\Models\VendorInvoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $vendorCompanyId = $user->company_id;

        // 4 Stats Cards
        $stats = [
            'pending_acceptance' => Shipment::forVendor($vendorCompanyId)
                ->where('vendor_status', VendorJobStatus::PendingAcceptance->value)
                ->count(),
            'in_progress' => Shipment::forVendor($vendorCompanyId)
                ->whereIn('vendor_status', [
                    VendorJobStatus::Accepted->value,
                    VendorJobStatus::InProgress->value,
                ])
                ->count(),
            'completed' => Shipment::forVendor($vendorCompanyId)
                ->where('vendor_status', VendorJobStatus::Completed->value)
                ->count(),
            'pending_invoice' => Shipment::forVendor($vendorCompanyId)
                ->where('vendor_status', VendorJobStatus::Completed->value)
                ->whereDoesntHave('vendorInvoice')
                ->count(),
        ];

        // My Job Orders (5 latest)
        $myJobOrders = Shipment::forVendor($vendorCompanyId)
            ->with(['company:id,name,company_code', 'serviceType:id,code,name', 'originLocation:id,code,name', 'destinationLocation:id,code,name'])
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'jo_number' => 'JO-'.str_pad((string) $s->id, 5, '0', STR_PAD_LEFT),
                'shipment_number' => $s->shipment_number,
                'customer_name' => $s->company?->name,
                'service' => $s->serviceType?->name,
                'origin' => $s->originLocation?->name,
                'destination' => $s->destinationLocation?->name,
                'due_date' => $s->estimated_arrival?->toDateString(),
                'vendor_status' => $s->vendor_status,
            ]);

        // Performance summary
        $performance = [
            'active_job_orders' => $stats['pending_acceptance'] + $stats['in_progress'],
            'completed_this_month' => Shipment::forVendor($vendorCompanyId)
                ->where('vendor_status', VendorJobStatus::Completed->value)
                ->whereMonth('completion_verified_at', now()->month)
                ->whereYear('completion_verified_at', now()->year)
                ->count(),
            'pending_acceptance' => $stats['pending_acceptance'],
            'invoice_outstanding' => VendorInvoice::where('vendor_company_id', $vendorCompanyId)
                ->whereIn('status', [
                    VendorInvoiceStatus::Submitted->value,
                    VendorInvoiceStatus::Approved->value,
                ])
                ->where('status', '!=', VendorInvoiceStatus::Paid->value)
                ->sum('total_amount'),
        ];

        // Upcoming Deadlines (top 5 job orders with due_date approaching, not yet completed)
        $upcomingDeadlines = Shipment::forVendor($vendorCompanyId)
            ->whereIn('vendor_status', [
                VendorJobStatus::PendingAcceptance->value,
                VendorJobStatus::Accepted->value,
                VendorJobStatus::InProgress->value,
                VendorJobStatus::WaitingVerification->value,
            ])
            ->whereNotNull('estimated_arrival')
            ->whereDate('estimated_arrival', '>=', now()->toDateString())
            ->orderBy('estimated_arrival')
            ->limit(5)
            ->get()
            ->map(function (Shipment $s) {
                $due = $s->estimated_arrival;
                $remaining = $due ? (int) Carbon::now()->startOfDay()->diffInDays($due, false) : null;

                return [
                    'id' => $s->id,
                    'jo_number' => 'JO-'.str_pad((string) $s->id, 5, '0', STR_PAD_LEFT),
                    'customer_name' => $s->company?->name,
                    'due_date' => $due?->toDateString(),
                    'remaining_days' => $remaining,
                    'vendor_status' => $s->vendor_status,
                ];
            });

        // Recent Activities (last 10 activities scoped to vendor's job orders/invoices/payments)
        $shipmentIds = Shipment::forVendor($vendorCompanyId)->pluck('shipments.id')->all();
        $invoiceIds = VendorInvoice::where('vendor_company_id', $vendorCompanyId)->pluck('id')->all();
        $recentActivities = CompanyActivity::query()
            ->where(function ($q) use ($shipmentIds, $invoiceIds) {
                $q->where(function ($q) use ($shipmentIds) {
                    $q->where('subject_type', Shipment::class)->whereIn('subject_id', $shipmentIds);
                })->orWhere(function ($q) use ($invoiceIds) {
                    $q->where('subject_type', VendorInvoice::class)->whereIn('subject_id', $invoiceIds);
                });
            })
            ->with('actor:id,name')
            ->orderByDesc('occurred_at')
            ->limit(10)
            ->get()
            ->map(fn (CompanyActivity $a) => [
                'id' => $a->id,
                'event_key' => $a->event_key,
                'actor_name' => $a->actor?->name,
                'occurred_at' => $a->occurred_at?->toIso8601String(),
            ]);

        // Pending Documents (job orders completed but invoice not yet created, or progress without evidence)
        $pendingDocuments = Shipment::forVendor($vendorCompanyId)
            ->with('vendorInvoice:id,shipment_id,status')
            ->whereIn('vendor_status', [
                VendorJobStatus::Completed->value,
                VendorJobStatus::WaitingVerification->value,
            ])
            ->get()
            ->filter(function (Shipment $s) {
                return $s->vendor_status === VendorJobStatus::Completed->value && $s->vendorInvoice === null;
            })
            ->take(5)
            ->map(fn (Shipment $s) => [
                'id' => $s->id,
                'jo_number' => 'JO-'.str_pad((string) $s->id, 5, '0', STR_PAD_LEFT),
                'document_key' => 'vendor_invoice',
                'status_key' => 'pending_submission',
                'action_key' => 'create',
                'action_url' => "/dashboard/vendor/invoices/create?job_order_id={$s->id}",
            ])
            ->values();

        return response()->json([
            'data' => [
                'stats' => $stats,
                'quick_actions' => [
                    'view_pending_jobs' => $stats['pending_acceptance'],
                    'create_invoice' => $stats['pending_invoice'],
                ],
                'my_job_orders' => $myJobOrders,
                'performance' => $performance,
                'upcoming_deadlines' => $upcomingDeadlines,
                'recent_activities' => $recentActivities,
                'pending_documents' => $pendingDocuments,
                'vendor_company' => $user->company ? [
                    'id' => $user->company->id,
                    'name' => $user->company->name,
                    'company_code' => $user->company->company_code,
                    'status' => $user->company->status,
                    'service_categories' => $user->company->service_categories ?? [],
                ] : null,
            ],
        ]);
    }
}
