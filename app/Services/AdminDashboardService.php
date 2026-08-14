<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingActivity;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceActivity;
use App\Models\Payment;
use App\Models\PaymentActivity;
use App\Models\ProofOfDelivery;
use App\Models\Shipment;
use App\Models\ShipmentTracking;
use App\Models\VendorInvoice;
use App\Models\VendorPayment;
use App\Models\User;
use Carbon\Carbon;

class AdminDashboardService
{
    public function build(array $input, ?User $user = null): array
    {
        $access = $user?->featureAccessList() ?? [];
        $can = fn (string $perm): bool => $user === null || $user->hasFeatureAccess($perm);
        $businessDate = Carbon::parse($input['business_date'] ?? now()->toDateString())->startOfDay();
        [$rangeStart, $rangeEnd] = $this->resolveDateRange($input, $businessDate);
        $monthStart = $businessDate->copy()->startOfMonth();
        $monthEnd = $businessDate->copy()->endOfMonth();

        $summary = $can('view_dashboard') ? $this->buildSummary($businessDate, $monthStart, $monthEnd) : [];
        $bookingStatusBreakdown = $can('view_bookings') ? $this->bookingStatusBreakdown($rangeStart, $rangeEnd) : [];
        $shipmentStatusBreakdown = $can('view_shipments') ? $this->shipmentStatusBreakdown() : [];
        $todayOperations = $can('view_operations') ? $this->todayOperations($businessDate) : [];
        $financeSummary = ($can('view_invoices') || $can('view_payments')) ? $this->financeSummary($rangeStart, $rangeEnd) : [];
        $containerSummary = $can('view_containers') ? $this->containerSummary() : [];
        $recentActivity = $can('view_dashboard') ? $this->recentActivity($access) : [];
        $notifications = $can('view_dashboard') ? $this->notifications($businessDate) : [];

        return [
            'filters' => [
                'period' => $input['period'] ?? 'today',
                'businessDate' => $businessDate->toDateString(),
                'dateFrom' => $rangeStart->toDateString(),
                'dateTo' => $rangeEnd->toDateString(),
            ],
            'summary' => $summary,
            'bookingStatusBreakdown' => $bookingStatusBreakdown,
            'shipmentStatusBreakdown' => $shipmentStatusBreakdown,
            'todayOperations' => $todayOperations,
            'financeSummary' => $financeSummary,
            'containerSummary' => $containerSummary,
            'recentActivity' => $recentActivity,
            'notifications' => $notifications,
        ];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveDateRange(array $input, Carbon $businessDate): array
    {
        $period = $input['period'] ?? 'today';

        return match ($period) {
            'week' => [$businessDate->copy()->startOfWeek(), $businessDate->copy()->endOfDay()],
            'month' => [$businessDate->copy()->startOfMonth(), $businessDate->copy()->endOfDay()],
            'custom' => [
                Carbon::parse($input['date_from'] ?? $businessDate)->startOfDay(),
                Carbon::parse($input['date_to'] ?? $businessDate)->endOfDay(),
            ],
            default => [$businessDate->copy()->startOfDay(), $businessDate->copy()->endOfDay()],
        };
    }

    private function buildSummary(Carbon $businessDate, Carbon $monthStart, Carbon $monthEnd): array
    {
        $today = $businessDate->toDateString();

        $outstandingReceivable = (float) Invoice::query()
            ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
            ->get()
            ->sum(function (Invoice $invoice) {
                $paid = (float) $invoice->payments()
                    ->whereIn('status', ['success', 'settlement'])
                    ->sum('amount');

                return max((float) $invoice->total_amount - $paid, 0);
            });

        $outstandingPayable = (float) VendorInvoice::query()
            ->whereNotIn('status', ['paid', 'cancelled', 'rejected'])
            ->get()
            ->sum(fn (VendorInvoice $invoice) => $invoice->outstandingAmount());

        return [
            'totalCustomers' => Company::where('status', 'active')->count(),
            'activeShipments' => Shipment::whereNotIn('status', ['completed', 'cancelled'])->count(),
            'bookingsToday' => Booking::whereDate('created_at', $today)->count(),
            'revenueThisMonth' => (float) Invoice::whereBetween('issued_date', [$monthStart, $monthEnd])
                ->whereIn('status', ['issued', 'partially_paid', 'paid', 'overdue'])
                ->sum('total_amount'),
            'outstandingReceivable' => round(max($outstandingReceivable, 0), 2),
            'outstandingPayable' => round(max($outstandingPayable, 0), 2),
            'activeCompanies' => Company::where('status', 'active')->count(),
            'overdueInvoices' => Invoice::whereIn('status', ['issued', 'partially_paid'])
                ->whereNotNull('due_date')
                ->where('due_date', '<', $today)
                ->count(),
            'pendingCompanyApprovals' => Company::where('status', 'pending')->count(),
            'paymentsToday' => Payment::whereIn('status', ['success', 'settlement'])
                ->whereDate('paid_at', $today)
                ->count(),
            'rackUtilization' => 0,
        ];
    }

    private function bookingStatusBreakdown(Carbon $rangeStart, Carbon $rangeEnd): array
    {
        $counts = Booking::query()
            ->whereBetween('created_at', [$rangeStart, $rangeEnd])
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'draft' => (int) ($counts['draft'] ?? 0),
            'submitted' => (int) ($counts['submitted'] ?? 0),
            'under_review' => (int) ($counts['under_review'] ?? 0),
            'confirmed' => (int) ($counts['approved'] ?? 0),
            'rejected' => (int) ($counts['rejected'] ?? 0),
        ];
    }

    private function shipmentStatusBreakdown(): array
    {
        $mapping = config('admin-dashboard.fsd_shipment_statuses', []);
        $breakdown = array_fill_keys(array_keys($mapping), 0);

        $shipments = Shipment::query()
            ->whereNotIn('status', ['cancelled'])
            ->get(['id', 'status']);

        foreach ($shipments as $shipment) {
            $status = strtolower((string) $shipment->status);

            $matched = false;
            foreach ($mapping as $fsdKey => $rawStatuses) {
                if (in_array($status, $rawStatuses, true)) {
                    $breakdown[$fsdKey] = ($breakdown[$fsdKey] ?? 0) + 1;
                    $matched = true;
                    break;
                }
            }

            if (! $matched && $status !== '') {
                $breakdown['planning'] = ($breakdown['planning'] ?? 0) + 1;
            }
        }

        return $breakdown;
    }

    private function todayOperations(Carbon $businessDate): array
    {
        $today = $businessDate->toDateString();

        return [
            'pickupToday' => Shipment::whereDate('created_at', '<=', $today)
                ->where('status', 'cargo_received')
                ->count(),
            'trainDepartureToday' => Shipment::where(function ($q) use ($today) {
                $q->whereDate('estimated_departure', $today)
                    ->orWhereDate('actual_departure', $today);
            })->whereIn('status', ['departed', 'train_departed', 'container_sealed', 'stuffing_container'])->count(),
            'trainArrivalToday' => Shipment::where(function ($q) use ($today) {
                $q->whereDate('estimated_arrival', $today)
                    ->orWhereDate('actual_arrival', $today);
            })->whereIn('status', ['arrived', 'train_arrived', 'departed', 'train_departed'])->count(),
            'deliveryToday' => Shipment::whereDate('updated_at', $today)
                ->where('status', 'ready_for_pickup')
                ->count(),
            'podWaitingUpload' => ProofOfDelivery::where('status', 'waiting_pod')->count(),
        ];
    }

    private function financeSummary(Carbon $rangeStart, Carbon $rangeEnd): array
    {
        $customerInvoice = (float) Invoice::whereBetween('issued_date', [$rangeStart, $rangeEnd])
            ->whereIn('status', ['issued', 'partially_paid', 'paid', 'overdue'])
            ->sum('total_amount');

        $customerPayment = (float) Payment::whereIn('status', ['success', 'settlement'])
            ->whereBetween('paid_at', [$rangeStart, $rangeEnd])
            ->sum('amount');

        $outstandingCustomer = (float) Invoice::query()
            ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
            ->get()
            ->sum(fn (Invoice $invoice) => max((float) $invoice->total_amount - (float) $invoice->payments()
                ->whereIn('status', ['success', 'settlement'])
                ->sum('amount'), 0));

        $vendorInvoice = (float) VendorInvoice::whereBetween('invoice_date', [$rangeStart, $rangeEnd])
            ->whereNotIn('status', ['cancelled', 'rejected'])
            ->sum('total_amount');

        $vendorPayment = (float) VendorPayment::whereBetween('payment_date', [$rangeStart, $rangeEnd])
            ->where('status', 'paid')
            ->sum('amount');

        $outstandingVendor = (float) VendorInvoice::query()
            ->whereNotIn('status', ['paid', 'cancelled', 'rejected'])
            ->get()
            ->sum(fn (VendorInvoice $invoice) => $invoice->outstandingAmount());

        return [
            'customerInvoice' => round($customerInvoice, 2),
            'customerPayment' => round($customerPayment, 2),
            'outstandingCustomer' => round($outstandingCustomer, 2),
            'outstandingVendor' => round($outstandingVendor, 2),
            'vendorInvoice' => round($vendorInvoice, 2),
            'vendorPayment' => round($vendorPayment, 2),
        ];
    }

    private function containerSummary(): array
    {
        return [
            'available' => 0,
            'reserved' => 0,
            'inTransit' => Shipment::whereNotIn('status', ['completed', 'cancelled'])->count(),
            'maintenance' => 0,
            'inactive' => 0,
        ];
    }

    private function recentActivity(array $access = []): array
    {
        $items = collect();
        $showAll = $access === [];
        $canBookings = $showAll || in_array('view_bookings', $access, true);
        $canFinance = $showAll || in_array('view_invoices', $access, true) || in_array('view_payments', $access, true);
        $canShipments = $showAll || in_array('view_shipments', $access, true);

        if ($canBookings) {
            BookingActivity::with('actor:id,name')
                ->orderByDesc('occurred_at')
                ->limit(8)
                ->get()
                ->each(function (BookingActivity $activity) use ($items) {
                    $items->push([
                        'time' => $activity->occurred_at?->format('H:i') ?? '',
                        'module' => 'Booking',
                        'activity' => $activity->title ?? $activity->description ?? 'Booking updated',
                        'user' => $activity->actor?->name ?? $activity->actor_role ?? 'System',
                        'occurredAt' => $activity->occurred_at?->toIso8601String(),
                    ]);
                });
        }

        if ($canFinance) {
            InvoiceActivity::with('actorUser:id,name')
                ->orderByDesc('occurred_at')
                ->limit(8)
                ->get()
                ->each(function (InvoiceActivity $activity) use ($items) {
                    $items->push([
                        'time' => $activity->occurred_at?->format('H:i') ?? '',
                        'module' => 'Finance',
                        'activity' => $activity->description ?? 'Invoice updated',
                        'user' => $activity->actorUser?->name ?? 'System',
                        'occurredAt' => $activity->occurred_at?->toIso8601String(),
                    ]);
                });

            PaymentActivity::with('actor:id,name')
                ->orderByDesc('occurred_at')
                ->limit(8)
                ->get()
                ->each(function (PaymentActivity $activity) use ($items) {
                    $items->push([
                        'time' => $activity->occurred_at?->format('H:i') ?? '',
                        'module' => 'Finance',
                        'activity' => $activity->description ?? 'Payment updated',
                        'user' => $activity->actor?->name ?? 'System',
                        'occurredAt' => $activity->occurred_at?->toIso8601String(),
                    ]);
                });
        }

        if ($canShipments) {
            ShipmentTracking::with('updatedByUser:id,name')
                ->orderByDesc('tracked_at')
                ->limit(8)
                ->get()
                ->each(function (ShipmentTracking $tracking) use ($items) {
                    $items->push([
                        'time' => $tracking->tracked_at?->format('H:i') ?? '',
                        'module' => 'Shipment',
                        'activity' => $tracking->notes ?? ('Status: '.$tracking->status),
                        'user' => $tracking->updatedByUser?->name ?? 'Operations',
                        'occurredAt' => $tracking->tracked_at?->toIso8601String(),
                    ]);
                });
        }

        return $items
            ->sortByDesc('occurredAt')
            ->take(10)
            ->values()
            ->map(fn (array $row) => [
                'time' => $row['time'],
                'module' => $row['module'],
                'activity' => $row['activity'],
                'user' => $row['user'],
            ])
            ->all();
    }

    private function notifications(Carbon $businessDate): array
    {
        $today = $businessDate->toDateString();
        $notifications = [];

        $pendingBookings = Booking::whereIn('status', ['submitted', 'under_review'])->count();
        if ($pendingBookings > 0) {
            $notifications[] = [
                'key' => 'pendingBookings',
                'count' => $pendingBookings,
                'link' => '/dashboard/admin/customer/bookings?status=under_review',
            ];
        }

        $readyOperation = $this->shipmentStatusBreakdown()['ready_operation'] ?? 0;
        if ($readyOperation > 0) {
            $notifications[] = [
                'key' => 'readyOperation',
                'count' => $readyOperation,
                'link' => '/dashboard/admin/customer/shipments?status=survey_completed',
            ];
        }

        $trainDepartureToday = $this->todayOperations($businessDate)['trainDepartureToday'];
        if ($trainDepartureToday > 0) {
            $notifications[] = [
                'key' => 'trainDepartureToday',
                'count' => $trainDepartureToday,
                'link' => '/dashboard/admin/operations/train-departure',
            ];
        }

        $podWaiting = $this->todayOperations($businessDate)['podWaitingUpload'];
        if ($podWaiting > 0) {
            $notifications[] = [
                'key' => 'podWaitingUpload',
                'count' => $podWaiting,
                'link' => '/dashboard/admin/operations/proof-of-delivery',
            ];
        }

        $invoicesDueToday = Invoice::whereDate('due_date', $today)
            ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
            ->count();
        if ($invoicesDueToday > 0) {
            $notifications[] = [
                'key' => 'invoicesDueToday',
                'count' => $invoicesDueToday,
                'link' => '/dashboard/admin/customer/invoices',
            ];
        }

        $vendorInvoicesPending = VendorInvoice::where('status', 'submitted')->count();
        if ($vendorInvoicesPending > 0) {
            $notifications[] = [
                'key' => 'vendorInvoicesPending',
                'count' => $vendorInvoicesPending,
                'link' => '/dashboard/admin/vendor/invoices',
            ];
        }

        $expiredPayments = Payment::where('status', Payment::STATUS_EXPIRED)->count();
        if ($expiredPayments > 0) {
            $notifications[] = [
                'key' => 'expiredPayments',
                'count' => $expiredPayments,
                'link' => '/dashboard/admin/customer/payments',
            ];
        }

        return $notifications;
    }
}
