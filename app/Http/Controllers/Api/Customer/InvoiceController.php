<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoiceActivity;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class InvoiceController extends Controller
{
    /** @var list<string> */
    private const ISSUED_DB_STATUSES = ['issued', 'unpaid'];

    /** @var list<string> */
    private const OPEN_DB_STATUSES = ['issued', 'partially_paid', 'unpaid', 'overdue'];

    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();
        $today = Carbon::today();

        $base = Invoice::query()->where('company_id', $user->company_id);

        $draft = (clone $base)->where('status', 'draft')->count();
        $paid = (clone $base)->where('status', 'paid')->count();
        $partiallyPaid = (clone $base)
            ->where('status', 'partially_paid')
            ->where(fn ($q) => $this->applyNotOverdueFilter($q, $today))
            ->count();
        $issued = (clone $base)
            ->whereIn('status', self::ISSUED_DB_STATUSES)
            ->where(fn ($q) => $this->applyNotOverdueFilter($q, $today))
            ->count();
        $overdueQuery = (clone $base)
            ->whereIn('status', self::OPEN_DB_STATUSES)
            ->whereNotNull('due_date')
            ->where('due_date', '<', $today);
        $this->applyOutstandingFilter($overdueQuery);
        $overdue = $overdueQuery->count();

        return response()->json([
            'data' => [
                'draft' => $draft,
                'issued' => $issued,
                'partially_paid' => $partiallyPaid,
                'paid' => $paid,
                'overdue' => $overdue,
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $today = Carbon::today();

        $validated = $request->validate([
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
            'search' => 'nullable|string|max:255',
            'status' => [
                'nullable',
                'string',
                Rule::in(['draft', 'issued', 'partially_paid', 'paid', 'overdue']),
            ],
            'invoice_date_from' => 'nullable|date',
            'invoice_date_to' => 'nullable|date',
            'due_date_from' => 'nullable|date',
            'due_date_to' => 'nullable|date',
        ]);

        $query = Invoice::query()
            ->where('company_id', $user->company_id)
            ->with([
                'shipment:id,shipment_number,waybill_number,booking_id',
                'shipment.booking:id,booking_number',
            ])
            ->withSum([
                'payments as paid_amount' => function ($q) {
                    $q->whereIn('status', ['success', 'settlement']);
                },
            ], 'amount');

        if (! empty($validated['status'])) {
            $st = $validated['status'];
            if ($st === 'overdue') {
                $query
                    ->whereIn('status', self::OPEN_DB_STATUSES)
                    ->whereNotNull('due_date')
                    ->where('due_date', '<', $today);
                $this->applyOutstandingFilter($query);
            } elseif ($st === 'issued') {
                $query
                    ->whereIn('status', self::ISSUED_DB_STATUSES)
                    ->where(fn ($q) => $this->applyNotOverdueFilter($q, $today));
            } elseif ($st === 'partially_paid') {
                $query
                    ->where('status', 'partially_paid')
                    ->where(fn ($q) => $this->applyNotOverdueFilter($q, $today));
            } else {
                $query->where('status', $st);
            }
        }

        if (! empty($validated['invoice_date_from'])) {
            $query->whereDate('issued_date', '>=', $validated['invoice_date_from']);
        }
        if (! empty($validated['invoice_date_to'])) {
            $query->whereDate('issued_date', '<=', $validated['invoice_date_to']);
        }
        if (! empty($validated['due_date_from'])) {
            $query->whereDate('due_date', '>=', $validated['due_date_from']);
        }
        if (! empty($validated['due_date_to'])) {
            $query->whereDate('due_date', '<=', $validated['due_date_to']);
        }

        if (! empty($validated['search'])) {
            $s = $validated['search'];
            $query->where(function ($q) use ($s) {
                $q->where('invoice_number', 'like', "%{$s}%")
                    ->orWhereHas('shipment', function ($sq) use ($s) {
                        $sq->where('waybill_number', 'like', "%{$s}%")
                            ->orWhere('shipment_number', 'like', "%{$s}%");
                    });
            });
        }

        $paginated = $query->orderBy('created_at', 'desc')->paginate($validated['per_page'] ?? 15);

        $paginated->getCollection()->transform(function (Invoice $inv) use ($today) {
            $paid = (float) ($inv->paid_amount ?? 0);
            $total = (float) $inv->total_amount;
            $outstanding = max($total - $paid, 0);
            $customerStatus = $this->resolveCustomerStatus((string) $inv->status, $inv->due_date, $outstanding, $today);

            return [
                'id' => $inv->id,
                'invoice_number' => $inv->invoice_number,
                'invoice_date' => $inv->issued_date?->toDateString(),
                'due_date' => $inv->due_date?->toDateString(),
                'total_amount' => $total,
                'paid_amount' => $paid,
                'outstanding_amount' => $outstanding,
                'status' => $customerStatus,
                'base_status' => (string) $inv->status,
                'shipment' => [
                    'id' => $inv->shipment?->id,
                    'shipment_number' => $inv->shipment?->shipment_number,
                    'waybill_number' => $inv->shipment?->waybill_number,
                    'booking_number' => $inv->shipment?->booking?->booking_number,
                ],
            ];
        });

        return response()->json($paginated);
    }

    public function show(Request $request, Invoice $invoice): JsonResponse
    {
        $user = $request->user();
        if ($invoice->company_id !== $user->company_id) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        $today = Carbon::today();

        $relations = [
            'company:id,name,postpaid_term_days',
            'shipment',
            'shipment.booking:id,booking_number',
            'shipment.originLocation:id,name',
            'shipment.destinationLocation:id,name',
            'shipment.serviceType:id,name,code',
            'items',
            'payments',
        ];

        $hasActivities = Schema::hasTable('invoice_activities');
        if ($hasActivities) {
            $relations[] = 'activities.actorUser:id,name';
        }

        $invoice->load($relations);

        if ($hasActivities) {
            $viewActivity = InvoiceActivity::create([
                'invoice_id' => $invoice->id,
                'actor_user_id' => $user->id,
                'event_key' => 'invoice_viewed',
                'description' => 'Customer membuka Invoice',
                'meta' => [
                    'ip' => $request->ip(),
                    'user_agent' => (string) $request->userAgent(),
                ],
                'occurred_at' => now(),
            ]);
            $invoice->activities->push($viewActivity);
        }

        $paidAmount = $invoice->paidAmount();
        $outstandingAmount = $invoice->outstandingAmount();
        $customerStatus = $this->resolveCustomerStatus(
            (string) $invoice->status,
            $invoice->due_date,
            $outstandingAmount,
            $today
        );

        $companySnapshot = $invoice->company_snapshot ?? null;
        $shipmentSnapshot = $invoice->shipment_snapshot ?? null;

        $customerName = $companySnapshot['name'] ?? $invoice->company?->name;
        $paymentTerms = $companySnapshot['payment_terms']
            ?? (($invoice->company?->postpaid_term_days ?? null) !== null ? ((string) $invoice->company->postpaid_term_days.' days') : null);

        $shipmentNo = $shipmentSnapshot['shipment_no'] ?? $invoice->shipment?->shipment_number;
        $bookingNo = $shipmentSnapshot['booking_no'] ?? $invoice->shipment?->booking?->booking_number;
        $cnNo = $shipmentSnapshot['cn_no'] ?? $invoice->shipment?->waybill_number;
        $routeOrigin = $shipmentSnapshot['origin'] ?? $invoice->shipment?->originLocation?->name;
        $routeDestination = $shipmentSnapshot['destination'] ?? $invoice->shipment?->destinationLocation?->name;
        $serviceType = $shipmentSnapshot['service_type'] ?? $invoice->shipment?->serviceType?->name;
        $shipmentCoverage = $shipmentSnapshot['shipment_coverage'] ?? $invoice->shipment?->shipment_coverage;

        $documents = [
            [
                'key' => 'invoice_pdf',
                'label' => 'Invoice PDF',
                'available' => true,
                'view_path' => "/customer/invoices/{$invoice->id}/pdf",
                'download_path' => "/customer/invoices/{$invoice->id}/pdf",
            ],
            [
                'key' => 'tax_invoice',
                'label' => 'Tax Invoice',
                'available' => $customerStatus === 'paid' || $invoice->status === 'paid',
                'view_path' => '/customer/documents/'.rawurlencode("tinv-{$invoice->id}").'/preview',
                'download_path' => '/customer/documents/'.rawurlencode("tinv-{$invoice->id}").'/download',
            ],
            [
                'key' => 'supporting',
                'label' => 'Supporting Documents',
                'available' => $invoice->shipment_id !== null,
                'list_path' => $invoice->shipment_id !== null
                    ? "/customer/documents?shipment_id={$invoice->shipment_id}&type=other_supporting"
                    : null,
            ],
        ];

        $paymentHistory = $invoice->payments
            ->sortByDesc(fn ($p) => $p->paid_at ?? $p->created_at)
            ->values()
            ->map(function ($p) {
                return [
                    'id' => $p->id,
                    'payment_date' => ($p->paid_at ?? $p->created_at)?->toIso8601String(),
                    'amount' => (float) $p->amount,
                    'payment_method' => $p->payment_type,
                    'reference_no' => $p->midtrans_order_id,
                    'status' => $p->status,
                ];
            });

        $timeline = collect();
        if ($invoice->issued_date !== null) {
            $timeline->push([
                'occurred_at' => $invoice->issued_date->toIso8601String(),
                'activity' => 'Invoice diterbitkan',
            ]);
            $timeline->push([
                'occurred_at' => $invoice->issued_date->toIso8601String(),
                'activity' => 'Invoice PDF dibuat',
            ]);
        }
        foreach ($invoice->payments as $p) {
            if (in_array($p->status, ['success', 'settlement'], true)) {
                $timeline->push([
                    'occurred_at' => ($p->paid_at ?? $p->created_at)?->toIso8601String(),
                    'activity' => 'Pembayaran Rp'.number_format((float) $p->amount, 0, ',', '.').' diterima',
                ]);
            }
        }
        if ($customerStatus === 'paid') {
            $lastPayment = $invoice->payments
                ->filter(fn ($p) => in_array($p->status, ['success', 'settlement'], true))
                ->sortByDesc(fn ($p) => $p->paid_at ?? $p->created_at)
                ->first();
            $paidAt = $lastPayment?->paid_at ?? $lastPayment?->created_at ?? $invoice->updated_at;
            $timeline->push([
                'occurred_at' => $paidAt?->toIso8601String(),
                'activity' => 'Status menjadi Paid',
            ]);
        }

        if ($hasActivities) {
            foreach ($invoice->activities as $act) {
                $timeline->push([
                    'occurred_at' => $act->occurred_at?->toIso8601String(),
                    'activity' => $act->description ?? $act->event_key,
                ]);
            }
        }

        $timeline = $timeline
            ->filter(fn ($e) => ! empty($e['occurred_at']))
            ->sortBy(fn ($e) => $e['occurred_at'])
            ->values();

        return response()->json([
            'data' => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'customer' => $customerName,
                'status' => $customerStatus,
                'invoice_date' => $invoice->issued_date?->toDateString(),
                'due_date' => $invoice->due_date?->toDateString(),
                'currency' => 'IDR',
                'payment_terms' => $paymentTerms,
                'remark' => $invoice->notes,
                'shipment' => [
                    'id' => $invoice->shipment?->id,
                    'booking_id' => $invoice->shipment?->booking_id ?? $invoice->shipment?->booking?->id,
                    'shipment_no' => $shipmentNo,
                    'booking_no' => $bookingNo,
                    'cn_no' => $cnNo,
                    'route' => ($routeOrigin && $routeDestination) ? ($routeOrigin.' → '.$routeDestination) : null,
                    'service_type' => $serviceType,
                    'shipment_coverage' => $shipmentCoverage,
                ],
                'items' => $invoice->items->map(fn ($it) => [
                    'id' => $it->id,
                    'description' => $it->description,
                    'qty' => (int) $it->quantity,
                    'unit_price' => (float) $it->unit_price,
                    'amount' => (float) $it->total_price,
                ])->values(),
                'summary' => $this->computeSummaryFromItems($invoice),
                'supporting_documents' => $documents,
                'payment_summary' => [
                    'invoice_amount' => (float) $invoice->total_amount,
                    'paid_amount' => $paidAmount,
                    'outstanding_amount' => $outstandingAmount,
                    'payment_status' => $customerStatus,
                ],
                'payment_history' => $paymentHistory,
                'activity_timeline' => $timeline,
                'actions' => [
                    'download_pdf_path' => "/customer/invoices/{$invoice->id}/pdf",
                    'can_pay_now' => $this->canUserPayNow($user, $invoice, $outstandingAmount),
                ],
            ],
        ]);
    }

    /**
     * Download invoice PDF (invoice harus milik company user).
     */
    public function downloadPdf(Request $request, Invoice $invoice)
    {
        if ($invoice->company_id !== $request->user()->company_id) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        $invoice->load(['company', 'shipment', 'items']);

        $pdf = Pdf::loadView('pdf.invoice', ['invoice' => $invoice]);

        return $pdf->download('invoice-'.$invoice->invoice_number.'.pdf');
    }

    private function applyOutstandingFilter(Builder $query): void
    {
        $query->whereRaw(
            "(invoices.total_amount - COALESCE((select sum(payments.amount) from payments where payments.invoice_id = invoices.id and payments.status in ('success','settlement')), 0)) > 0"
        );
    }

    private function applyNotOverdueFilter(Builder $query, Carbon $today): void
    {
        $query->where(function ($q) use ($today) {
            $q->whereNull('due_date')->orWhere('due_date', '>=', $today);
        });
    }

    private function normalizeBaseStatus(string $status): string
    {
        return match ($status) {
            'unpaid', 'overdue' => 'issued',
            default => $status,
        };
    }

    private function resolveCustomerStatus(
        string $dbStatus,
        ?Carbon $dueDate,
        float $outstanding,
        Carbon $today,
    ): string {
        $normalized = $this->normalizeBaseStatus($dbStatus);

        if (in_array($normalized, ['issued', 'partially_paid'], true)
            && $dueDate !== null
            && $dueDate->lt($today)
            && $outstanding > 0) {
            return 'overdue';
        }

        if ($dbStatus === 'paid') {
            return 'paid';
        }

        return $normalized;
    }

    /**
     * @return array{subtotal: float, discount: float, additional_charge: float, ppn: float, grand_total: float}
     */
    private function computeSummaryFromItems(Invoice $invoice): array
    {
        if ($invoice->items->isEmpty()) {
            return [
                'subtotal' => (float) $invoice->subtotal,
                'discount' => 0.0,
                'additional_charge' => 0.0,
                'ppn' => (float) $invoice->tax_amount,
                'grand_total' => (float) $invoice->total_amount,
            ];
        }

        $subtotal = 0.0;
        $discount = 0.0;
        $additionalCharge = 0.0;

        foreach ($invoice->items as $item) {
            $amount = (float) $item->total_price;
            $desc = strtolower((string) $item->description);

            if ($amount < 0 || str_contains($desc, 'discount')) {
                $discount += abs($amount);

                continue;
            }

            if (str_contains($desc, 'additional charge') || str_contains($desc, 'additional service')) {
                $additionalCharge += $amount;

                continue;
            }

            $subtotal += $amount;
        }

        return [
            'subtotal' => round($subtotal, 2),
            'discount' => round($discount, 2),
            'additional_charge' => round($additionalCharge, 2),
            'ppn' => (float) $invoice->tax_amount,
            'grand_total' => (float) $invoice->total_amount,
        ];
    }

    private function canUserPayNow(User $user, Invoice $invoice, float $outstandingAmount): bool
    {
        if ($outstandingAmount <= 0) {
            return false;
        }

        if (in_array($invoice->status, ['draft', 'cancelled', 'paid'], true)) {
            return false;
        }

        if ($user->hasRole('viewer')) {
            return false;
        }

        return true;
    }
}
