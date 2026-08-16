<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingActivity;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Shipment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    /** Maximum rows shown in each dashboard table. */
    private const DASHBOARD_TABLE_LIMIT = 5;

    /** Maximum items shown in the recent-notifications timeline. */
    private const DASHBOARD_NOTIFICATION_LIMIT = 5;

    /** Maximum notifications fetched for the full notifications list. */
    private const NOTIFICATIONS_LIST_MAX = 100;

    /** Shipment statuses that count as "completed" for active-scope filter. */
    private const COMPLETED_SHIPMENT_STATUSES = ['completed', 'cancelled'];

    /** Invoice statuses that count as "unpaid" (issued + partially paid, incl. legacy labels). */
    private const UNPAID_INVOICE_STATUSES = ['issued', 'partially_paid', 'unpaid', 'overdue'];

    /**
     * Aggregated payload for the customer dashboard.
     *  - 6 stat cards
     *  - 4 recent tables (max 5 rows each)
     *  - 5 latest activity notifications (derived from bookings/shipments/invoices/payments)
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $companyId = $user->company_id;

        if (! $companyId) {
            return response()->json([
                'data' => $this->emptyPayload(),
            ]);
        }

        return response()->json([
            'data' => [
                'cards' => $this->buildCards($companyId),
                'recent' => $this->buildRecentLists($companyId),
                'notifications' => $this->buildNotifications($companyId, self::DASHBOARD_NOTIFICATION_LIMIT),
            ],
        ]);
    }

    /**
     * Paginated activity notifications for the customer notifications page.
     */
    public function notifications(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;

        if (! $companyId) {
            return response()->json([
                'data' => [],
                'meta' => [
                    'current_page' => 1,
                    'per_page' => 15,
                    'total' => 0,
                    'last_page' => 1,
                ],
            ]);
        }

        $perPage = min(max((int) ($request->per_page ?? 15), 1), 50);
        $page = max((int) ($request->page ?? 1), 1);

        $all = $this->buildNotifications($companyId, self::NOTIFICATIONS_LIST_MAX);
        $total = count($all);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);
        $offset = ($page - 1) * $perPage;

        return response()->json([
            'data' => array_slice($all, $offset, $perPage),
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
            ],
        ]);
    }

    // ── Cards ─────────────────────────────────────────────────────────────
    private function buildCards(int $companyId): array
    {
        $bookingBase = Booking::where('company_id', $companyId);

        $bookingDraft = (clone $bookingBase)
            ->where('status', 'draft')
            ->count();

        $bookingSubmitted = (clone $bookingBase)
            ->where('status', 'submitted')
            ->count();

        $shipmentBase = Shipment::where('company_id', $companyId);

        $shipmentActive = (clone $shipmentBase)
            ->whereNotIn('status', self::COMPLETED_SHIPMENT_STATUSES)
            ->count();

        $shipmentCompleted = (clone $shipmentBase)
            ->where('status', 'completed')
            ->count();

        $invoiceUnpaid = Invoice::where('company_id', $companyId)
            ->whereIn('status', self::UNPAID_INVOICE_STATUSES)
            ->count();

        // Outstanding Amount = total_amount of every not-fully-paid invoice,
        // minus any successful payments already recorded against it.
        $unpaidTotal = (float) Invoice::where('company_id', $companyId)
            ->whereIn('status', self::UNPAID_INVOICE_STATUSES)
            ->sum('total_amount');

        $unpaidPaid = (float) Payment::whereIn('status', ['success', 'settlement'])
            ->whereHas('invoice', function ($q) use ($companyId) {
                $q->where('company_id', $companyId)
                    ->whereIn('status', self::UNPAID_INVOICE_STATUSES);
            })
            ->sum('amount');

        $outstandingAmount = $unpaidTotal - $unpaidPaid;

        return [
            'booking_draft' => (int) $bookingDraft,
            'booking_submitted' => (int) $bookingSubmitted,
            'shipment_active' => (int) $shipmentActive,
            'shipment_completed' => (int) $shipmentCompleted,
            'invoice_unpaid' => (int) $invoiceUnpaid,
            'invoice_outstanding_amount' => max(0.0, $outstandingAmount),
        ];
    }

    // ── Recent tables ─────────────────────────────────────────────────────
    private function buildRecentLists(int $companyId): array
    {
        $shipments = Shipment::with([
            'originLocation:id,name,code',
            'destinationLocation:id,name,code',
            'serviceType:id,name,code',
        ])
            ->where('company_id', $companyId)
            ->whereNotIn('status', self::COMPLETED_SHIPMENT_STATUSES)
            ->orderByDesc('created_at')
            ->limit(self::DASHBOARD_TABLE_LIMIT)
            ->get()
            ->map(fn (Shipment $s) => $this->serializeShipment($s))
            ->all();

        $bookings = Booking::with([
            'originLocation:id,name,code',
            'destinationLocation:id,name,code',
            'serviceType:id,name,code',
        ])
            ->where('company_id', $companyId)
            ->orderByDesc('created_at')
            ->limit(self::DASHBOARD_TABLE_LIMIT)
            ->get()
            ->map(fn (Booking $b) => $this->serializeBooking($b))
            ->all();

        $invoices = Invoice::with([
            'shipment:id,shipment_number,waybill_number',
            'payments' => fn ($q) => $q->where('status', 'success'),
        ])
            ->where('company_id', $companyId)
            ->whereIn('status', self::UNPAID_INVOICE_STATUSES)
            ->orderByDesc('issued_date')
            ->orderByDesc('created_at')
            ->limit(self::DASHBOARD_TABLE_LIMIT)
            ->get()
            ->map(fn (Invoice $i) => $this->serializeInvoice($i))
            ->all();

        $payments = Payment::with([
            'invoice:id,invoice_number,total_amount,company_id',
        ])
            ->whereHas('invoice', fn ($q) => $q->where('company_id', $companyId))
            ->where('status', 'success')
            ->orderByDesc('paid_at')
            ->limit(self::DASHBOARD_TABLE_LIMIT)
            ->get()
            ->map(fn (Payment $p) => $this->serializePayment($p))
            ->all();

        return [
            'shipments' => $shipments,
            'bookings' => $bookings,
            'invoices' => $invoices,
            'payments' => $payments,
        ];
    }

    // ── Notifications (derived from activity across 4 sources) ───────────
    private function buildNotifications(int $companyId, int $limit): array
    {
        $fetchLimit = max($limit * 4, $limit);

        $bookingSubmitEvents = BookingActivity::query()
            ->where('activity_type', 'submitted')
            ->whereHas('booking', fn ($q) => $q->where('company_id', $companyId))
            ->with('booking:id,booking_number,company_id')
            ->orderByDesc('occurred_at')
            ->limit($fetchLimit)
            ->get()
            ->map(function (BookingActivity $activity) {
                $booking = $activity->booking;

                return [
                    'id' => "bk_submitted_{$activity->id}",
                    'type' => 'booking_submitted',
                    'ref_id' => $booking?->id,
                    'ref_type' => 'booking',
                    'ref_number' => $booking?->booking_number,
                    'occurred_at' => optional($activity->occurred_at)->toIso8601String(),
                    'link' => $booking ? "/dashboard/booking/{$booking->id}" : '/dashboard/booking',
                ];
            });

        $bookingApprovedEvents = Booking::where('company_id', $companyId)
            ->whereNotNull('approved_at')
            ->orderByDesc('approved_at')
            ->limit($fetchLimit)
            ->get(['id', 'booking_number', 'approved_at'])
            ->map(function (Booking $b) {
                return [
                    'id' => "bk_approved_{$b->id}",
                    'type' => 'booking_approved',
                    'ref_id' => $b->id,
                    'ref_type' => 'booking',
                    'ref_number' => $b->booking_number,
                    'occurred_at' => $b->approved_at->toIso8601String(),
                    'link' => "/dashboard/booking/{$b->id}",
                ];
            });

        $shipmentEvents = Shipment::with(['destinationLocation:id,name'])
            ->where('company_id', $companyId)
            ->orderByDesc('created_at')
            ->limit($limit * 2)
            ->get(['id', 'shipment_number', 'actual_departure', 'actual_arrival'])
            ->flatMap(function (Shipment $s) {
                $events = [];
                if ($s->actual_departure) {
                    $events[] = [
                        'id' => "sh_departed_{$s->id}",
                        'type' => 'shipment_departed',
                        'ref_id' => $s->id,
                        'ref_type' => 'shipment',
                        'ref_number' => $s->shipment_number,
                        'occurred_at' => Carbon::parse($s->actual_departure)->toIso8601String(),
                        'link' => "/dashboard/shipments/{$s->id}",
                    ];
                }
                if ($s->actual_arrival) {
                    $destination = $s->destinationLocation?->name ?? '';
                    $events[] = [
                        'id' => "sh_arrived_{$s->id}",
                        'type' => 'shipment_arrived',
                        'ref_id' => $s->id,
                        'ref_type' => 'shipment',
                        'ref_number' => $s->shipment_number,
                        'destination' => $destination,
                        'occurred_at' => Carbon::parse($s->actual_arrival)->toIso8601String(),
                        'link' => "/dashboard/shipments/{$s->id}",
                    ];
                }

                return $events;
            });

        $invoiceEvents = Invoice::where('company_id', $companyId)
            ->orderByDesc('issued_date')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['id', 'invoice_number', 'issued_date', 'created_at'])
            ->map(function (Invoice $i) {
                return [
                    'id' => "iv_issued_{$i->id}",
                    'type' => 'invoice_issued',
                    'ref_id' => $i->id,
                    'ref_type' => 'invoice',
                    'ref_number' => $i->invoice_number,
                    'occurred_at' => ($i->issued_date
                        ? Carbon::parse($i->issued_date)
                        : $i->created_at
                    )?->toIso8601String(),
                    'link' => "/dashboard/invoices/{$i->id}",
                ];
            });

        $paymentEvents = Payment::with('invoice:id,invoice_number,company_id')
            ->whereHas('invoice', fn ($q) => $q->where('company_id', $companyId))
            ->where('status', 'success')
            ->orderByDesc('paid_at')
            ->limit($limit)
            ->get()
            ->map(function (Payment $p) {
                return [
                    'id' => "py_paid_{$p->id}",
                    'type' => 'payment_received',
                    'ref_id' => $p->invoice_id,
                    'ref_type' => 'invoice',
                    'ref_number' => $p->invoice?->invoice_number,
                    'occurred_at' => optional($p->paid_at)->toIso8601String(),
                    'link' => "/dashboard/invoices/{$p->invoice_id}",
                ];
            });

        return collect()
            ->merge($bookingSubmitEvents)
            ->merge($bookingApprovedEvents)
            ->merge($shipmentEvents)
            ->merge($invoiceEvents)
            ->merge($paymentEvents)
            ->filter(fn ($n) => ! empty($n['occurred_at']))
            ->sortByDesc(fn ($n) => $n['occurred_at'])
            ->values()
            ->take($limit)
            ->all();
    }

    // ── Serializers ───────────────────────────────────────────────────────
    private function serializeShipment(Shipment $s): array
    {
        return [
            'id' => $s->id,
            'shipment_number' => $s->shipment_number,
            'waybill_number' => $s->waybill_number,
            'route' => sprintf(
                '%s → %s',
                $s->originLocation?->name ?? '—',
                $s->destinationLocation?->name ?? '—'
            ),
            'service' => $s->serviceType?->name ?? '—',
            'service_code' => $s->serviceType?->code ?? null,
            'current_status' => $s->status,
            'eta' => optional($s->estimated_arrival)?->toDateString(),
        ];
    }

    private function serializeBooking(Booking $b): array
    {
        return [
            'id' => $b->id,
            'booking_number' => $b->booking_number,
            'booking_date' => optional($b->created_at)?->toDateString(),
            'route' => sprintf(
                '%s → %s',
                $b->originLocation?->name ?? '—',
                $b->destinationLocation?->name ?? '—'
            ),
            'service' => $b->serviceType?->name ?? '—',
            'service_code' => $b->serviceType?->code ?? null,
            'status' => $b->status,
        ];
    }

    private function serializeInvoice(Invoice $i): array
    {
        $paid = (float) $i->payments->sum('amount');
        $total = (float) $i->total_amount;
        $outstanding = max(0.0, $total - $paid);

        return [
            'id' => $i->id,
            'invoice_number' => $i->invoice_number,
            'due_date' => optional($i->due_date)?->toDateString(),
            'amount' => $total,
            'outstanding' => $outstanding,
            'status' => $i->status,
            'shipment_id' => $i->shipment_id,
            'shipment_number' => $i->shipment?->shipment_number,
        ];
    }

    private function serializePayment(Payment $p): array
    {
        return [
            'id' => $p->id,
            'invoice_id' => $p->invoice_id,
            'invoice_number' => $p->invoice?->invoice_number ?? '—',
            'amount' => (float) $p->amount,
            'method' => $p->payment_type ?? '—',
            'status' => $p->status,
            'paid_at' => optional($p->paid_at)?->toIso8601String(),
            'paid_at_date' => optional($p->paid_at)?->toDateString(),
        ];
    }

    private function emptyPayload(): array
    {
        return [
            'cards' => [
                'booking_draft' => 0,
                'booking_submitted' => 0,
                'shipment_active' => 0,
                'shipment_completed' => 0,
                'invoice_unpaid' => 0,
                'invoice_outstanding_amount' => 0.0,
            ],
            'recent' => [
                'shipments' => [],
                'bookings' => [],
                'invoices' => [],
                'payments' => [],
            ],
            'notifications' => [],
        ];
    }
}
