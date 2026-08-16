<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DocumentType;
use App\Models\Booking;
use App\Models\BookingAttachment;
use App\Models\BookingContainer;
use App\Models\BookingPackage;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Shipment;
use App\Models\ShipmentTrackingPhoto;
use Illuminate\Support\Collection;

/**
 * Virtual aggregation of all documents a company can see in the customer
 * portal "Documents" page. Pulls from existing tables; no new persistence.
 */
class DocumentAggregatorService
{
    private const MSDS_CONTAINER_ID_OFFSET = 1_000_000_000;

    /** FSD filter values → document_type(s) */
    private const FILTER_TYPE_MAP = [
        'booking' => ['booking_attachment', 'msds_file'],
        'shipment' => ['consignment_note'],
        'invoice' => ['invoice'],
        'tax_invoice' => ['tax_invoice'],
        'pod' => ['proof_of_delivery'],
        'delivery_order' => ['delivery_order'],
        'other' => ['other_supporting'],
        // legacy bucket filters
        'billing' => ['invoice', 'tax_invoice', 'payment_receipt'],
    ];

    public function __construct(
        private DocumentPdfService $pdf,
    ) {}

    /**
     * 4 stat buckets: total, booking, shipment, billing.
     */
    public function statsForCompany(int $companyId): array
    {
        $booking = $this->collectBookingDocuments($companyId, [])->count();
        $shipment = $this->collectShipmentDocuments($companyId, [])->count();
        $billing = $this->collectBillingDocuments($companyId, [])->count();

        return [
            'total' => $booking + $shipment + $billing,
            'booking' => $booking,
            'shipment' => $shipment,
            'billing' => $billing,
        ];
    }

    /**
     * List documents matching filters. Each row is a normalized payload
     * with: id, document_type, name, shipment_id, booking_id, upload_date,
     * uploaded_by, format, available.
     *
     * @return array{rows: array, total: int}
     */
    public function listForCompany(int $companyId, array $filters = []): array
    {
        $perPage = (int) ($filters['per_page'] ?? 15);
        $page = max(1, (int) ($filters['page'] ?? 1));

        $all = collect()
            ->merge($this->collectBookingDocuments($companyId, $filters))
            ->merge($this->collectShipmentDocuments($companyId, $filters))
            ->merge($this->collectBillingDocuments($companyId, $filters));

        $type = $filters['type'] ?? null;
        if ($type !== null && $type !== '') {
            if (isset(self::FILTER_TYPE_MAP[$type])) {
                $allowed = self::FILTER_TYPE_MAP[$type];
                $all = $all->filter(fn (array $r) => in_array($r['document_type'] ?? '', $allowed, true));
            } else {
                $all = $all->filter(fn (array $r) => ($r['document_type'] ?? null) === $type);
            }
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $needle = strtolower($search);
            $all = $all->filter(function (array $r) use ($needle) {
                $haystacks = [
                    $r['name'] ?? '',
                    $r['shipment_no'] ?? '',
                    $r['shipment_number'] ?? '',
                    $r['booking_no'] ?? '',
                    $r['cn_no'] ?? '',
                ];
                foreach ($haystacks as $h) {
                    if ($h !== '' && str_contains(strtolower((string) $h), $needle)) {
                        return true;
                    }
                }

                return false;
            });
        }

        $shipmentId = $filters['shipment_id'] ?? null;
        if ($shipmentId) {
            $all = $all->filter(fn (array $r) => (int) ($r['shipment_id'] ?? 0) === (int) $shipmentId);
        }

        $dateFrom = $filters['date_from'] ?? null;
        $dateTo = $filters['date_to'] ?? null;
        if ($dateFrom || $dateTo) {
            $all = $all->filter(function (array $r) use ($dateFrom, $dateTo) {
                $d = $r['upload_date'] ?? null;
                if (! $d) {
                    return false;
                }
                $ts = strtotime((string) $d);
                if ($dateFrom && $ts < strtotime((string) $dateFrom)) {
                    return false;
                }
                if ($dateTo && $ts > strtotime((string) $dateTo.' 23:59:59')) {
                    return false;
                }

                return true;
            });
        }

        $all = $all->sortByDesc(fn (array $r) => (string) ($r['upload_date'] ?? ''))->values();

        $total = $all->count();
        $rows = $all->slice(($page - 1) * $perPage, $perPage)->values()->all();

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Fetch a single document by its composite id (e.g. "cn-12"). Returns
     * null when not found or not owned by the given company.
     */
    public function findForCompany(string $id, int $companyId): ?array
    {
        [$type, $pk] = DocumentType::parseId($id);
        if ($type === null || ! $pk) {
            return null;
        }

        return match ($type) {
            DocumentType::BookingAttachment => $this->bookingAttachmentDocument($pk, $companyId),
            DocumentType::ConsignmentNote => $this->consignmentNoteDocument($pk, $companyId),
            DocumentType::DeliveryOrder => $this->deliveryOrderDocument($pk, $companyId),
            DocumentType::ProofOfDelivery => $this->podDocument($pk, $companyId),
            DocumentType::Invoice => $this->invoiceDocument($pk, $companyId),
            DocumentType::TaxInvoice => $this->taxInvoiceDocument($pk, $companyId),
            DocumentType::PaymentReceipt => $this->paymentReceiptDocument($pk, $companyId),
            DocumentType::OtherSupporting => $this->otherSupportingDocument($pk, $companyId),
            DocumentType::MsdsFile => $this->msdsDocument($pk, $companyId),
        };
    }

    /**
     * Resolve shipment dropdown options for the filter (already loaded in
     * booking-detail flow). Returns up to 200 most recent shipments.
     */
    public function shipmentOptions(int $companyId): array
    {
        return Shipment::query()
            ->where('company_id', $companyId)
            ->orderByDesc('created_at')
            ->limit(200)
            ->get(['id', 'shipment_number', 'waybill_number'])
            ->map(fn ($s) => [
                'id' => $s->id,
                'label' => ($s->shipment_number ?? 'SHP-').($s->waybill_number ? ' · '.$s->waybill_number : ''),
            ])
            ->all();
    }

    // ──────────────────────────────────────────────────────────────
    //  Collectors
    // ──────────────────────────────────────────────────────────────

    private function collectBookingDocuments(int $companyId, array $filters): Collection
    {
        $rows = BookingAttachment::query()
            ->whereIn('booking_id', Booking::query()->where('company_id', $companyId)->select('id'))
            ->with(['booking:id,booking_number,company_id', 'booking.company:id,name', 'uploader:id,name'])
            ->orderByDesc('created_at')
            ->get();

        $attachments = $rows->map(function (BookingAttachment $a) {
            $type = $this->attachmentDocumentType($a);

            return [
                'id' => $type->prefix().'-'.$a->id,
                'document_type' => $type->value,
                'document_type_label' => $this->labelFor($type),
                'name' => $a->original_name,
                'format' => $this->extension($a->original_name ?? $a->file_path),
                'mime_type' => $a->mime_type,
                'preview_supported' => $this->isPreviewSupported($a->original_name ?? $a->file_path),
                'upload_date' => optional($a->created_at)->toIso8601String(),
                'uploaded_by' => $a->uploader?->name ?? '—',
                'shipment_id' => null,
                'shipment_no' => null,
                'shipment_number' => null,
                'booking_id' => $a->booking_id,
                'booking_no' => $a->booking?->booking_number,
                'cn_no' => null,
                'bucket' => $type->bucket(),
                'available' => true,
                'source' => [
                    'kind' => $type === DocumentType::OtherSupporting ? 'other_supporting' : 'booking_attachment',
                    'id' => $a->id,
                    'file_path' => $a->file_path,
                ],
            ];
        });

        return $attachments->concat($this->collectMsdsDocuments($companyId));
    }

    private function collectMsdsDocuments(int $companyId): Collection
    {
        $out = collect();
        $bookingIds = Booking::query()->where('company_id', $companyId)->pluck('id');

        $packages = BookingPackage::query()
            ->whereIn('booking_id', $bookingIds)
            ->whereNotNull('msds_file_path')
            ->where('msds_file_path', '!=', '')
            ->with(['booking:id,booking_number,company_id', 'booking.company:id,name'])
            ->orderByDesc('updated_at')
            ->get();

        foreach ($packages as $p) {
            $name = 'MSDS/SDS — '.($p->description ?: 'Package #'.$p->sequence);
            $out->push([
                'id' => DocumentType::MsdsFile->prefix().'-'.$p->id,
                'document_type' => DocumentType::MsdsFile->value,
                'document_type_label' => $this->labelFor(DocumentType::MsdsFile),
                'name' => $name,
                'format' => $this->extension($p->msds_file_path) ?? 'pdf',
                'mime_type' => 'application/pdf',
                'preview_supported' => $this->isPreviewSupported($p->msds_file_path),
                'upload_date' => optional($p->updated_at ?? $p->created_at)->toIso8601String(),
                'uploaded_by' => 'Customer',
                'shipment_id' => null,
                'shipment_no' => null,
                'shipment_number' => null,
                'booking_id' => $p->booking_id,
                'booking_no' => $p->booking?->booking_number,
                'cn_no' => null,
                'bucket' => DocumentType::MsdsFile->bucket(),
                'available' => true,
                'source' => [
                    'kind' => 'msds_file',
                    'entity' => 'package',
                    'id' => $p->id,
                    'file_path' => $p->msds_file_path,
                ],
            ]);
        }

        $containers = BookingContainer::query()
            ->whereIn('booking_id', $bookingIds)
            ->whereNotNull('msds_file_path')
            ->where('msds_file_path', '!=', '')
            ->with(['booking:id,booking_number,company_id', 'booking.company:id,name'])
            ->orderByDesc('updated_at')
            ->get();

        foreach ($containers as $c) {
            $name = 'MSDS/SDS — '.($c->cargo_description ?: 'Container #'.$c->sequence);
            $virtualId = self::MSDS_CONTAINER_ID_OFFSET + $c->id;
            $out->push([
                'id' => DocumentType::MsdsFile->prefix().'-'.$virtualId,
                'document_type' => DocumentType::MsdsFile->value,
                'document_type_label' => $this->labelFor(DocumentType::MsdsFile),
                'name' => $name,
                'format' => $this->extension($c->msds_file_path) ?? 'pdf',
                'mime_type' => 'application/pdf',
                'preview_supported' => $this->isPreviewSupported($c->msds_file_path),
                'upload_date' => optional($c->updated_at ?? $c->created_at)->toIso8601String(),
                'uploaded_by' => 'Customer',
                'shipment_id' => null,
                'shipment_no' => null,
                'shipment_number' => null,
                'booking_id' => $c->booking_id,
                'booking_no' => $c->booking?->booking_number,
                'cn_no' => null,
                'bucket' => DocumentType::MsdsFile->bucket(),
                'available' => true,
                'source' => [
                    'kind' => 'msds_file',
                    'entity' => 'container',
                    'id' => $c->id,
                    'file_path' => $c->msds_file_path,
                ],
            ]);
        }

        return $out;
    }

    private function attachmentDocumentType(BookingAttachment $a): DocumentType
    {
        $cat = strtolower(trim((string) ($a->category ?? 'general')));

        return in_array($cat, ['others', 'other', 'internal'], true)
            ? DocumentType::OtherSupporting
            : DocumentType::BookingAttachment;
    }

    private function collectShipmentDocuments(int $companyId, array $filters): Collection
    {
        $out = collect();

        $shipments = Shipment::query()
            ->where('company_id', $companyId)
            ->with(['booking:id,booking_number'])
            ->get();

        foreach ($shipments as $s) {
            $rawStatus = strtolower((string) $s->status);

            // CN: available whenever shipment has a waybill_number
            if (! empty($s->waybill_number)) {
                $out->push($this->consignmentNoteRow($s));
            }

            // Delivery Order: available after arrival
            $doAvailableStatuses = ['ready_for_pickup', 'arrived', 'train_arrived', 'unloading', 'container_unloading', 'completed'];
            if (in_array($rawStatus, $doAvailableStatuses, true)) {
                $out->push($this->deliveryOrderRow($s));
            }

            // POD: any tracking photo on this shipment
            $podPhotos = ShipmentTrackingPhoto::query()
                ->whereHas('tracking', fn ($q) => $q->where('shipment_id', $s->id))
                ->orderByDesc('id')
                ->get();

            foreach ($podPhotos as $p) {
                $out->push($this->podRow($s, $p));
            }
        }

        return $out;
    }

    private function collectBillingDocuments(int $companyId, array $filters): Collection
    {
        $out = collect();

        $invoices = Invoice::query()
            ->where('company_id', $companyId)
            ->with(['shipment:id,shipment_number,waybill_number', 'shipment.booking:id,booking_number', 'latestPayment'])
            ->orderByDesc('created_at')
            ->get();

        foreach ($invoices as $inv) {
            $shipmentNo = $inv->shipment?->shipment_number;
            $bookingNo = $inv->shipment?->booking?->booking_number;

            // Invoice PDF
            $out->push([
                'id' => DocumentType::Invoice->prefix().'-'.$inv->id,
                'document_type' => DocumentType::Invoice->value,
                'document_type_label' => 'Invoice',
                'name' => 'Invoice '.($inv->invoice_number ?? ('#'.$inv->id)),
                'format' => 'pdf',
                'mime_type' => 'application/pdf',
                'preview_supported' => true,
                'upload_date' => optional($inv->created_at)->toIso8601String(),
                'uploaded_by' => $inv->createdByUser?->name ?? 'Finance',
                'shipment_id' => $inv->shipment_id,
                'shipment_no' => $shipmentNo,
                'shipment_number' => $shipmentNo,
                'booking_id' => null,
                'booking_no' => $bookingNo,
                'cn_no' => $inv->shipment?->waybill_number,
                'bucket' => DocumentType::Invoice->bucket(),
                'available' => true,
                'source' => ['kind' => 'invoice', 'id' => $inv->id],
            ]);

            // Tax Invoice — only when paid
            if (strtolower((string) $inv->status) === 'paid') {
                $out->push([
                    'id' => DocumentType::TaxInvoice->prefix().'-'.$inv->id,
                    'document_type' => DocumentType::TaxInvoice->value,
                    'document_type_label' => 'Tax Invoice',
                    'name' => 'Tax Invoice '.($inv->invoice_number ?? ('#'.$inv->id)),
                    'format' => 'pdf',
                    'mime_type' => 'application/pdf',
                    'preview_supported' => true,
                    'upload_date' => optional($inv->updated_at)->toIso8601String(),
                    'uploaded_by' => 'Finance',
                    'shipment_id' => $inv->shipment_id,
                    'shipment_no' => $shipmentNo,
                    'shipment_number' => $shipmentNo,
                    'booking_id' => null,
                    'booking_no' => $bookingNo,
                    'cn_no' => $inv->shipment?->waybill_number,
                    'bucket' => DocumentType::TaxInvoice->bucket(),
                    'available' => true,
                    'source' => ['kind' => 'tax_invoice', 'id' => $inv->id],
                ]);
            }

            // Payment Receipt — for each successful payment
            $payments = Payment::query()->where('invoice_id', $inv->id)->where('status', 'success')->get();
            foreach ($payments as $pay) {
                $out->push([
                    'id' => DocumentType::PaymentReceipt->prefix().'-'.$pay->id,
                    'document_type' => DocumentType::PaymentReceipt->value,
                    'document_type_label' => 'Payment Receipt',
                    'name' => 'Payment Receipt '.($pay->midtrans_order_id ?? ('#'.$pay->id)),
                    'format' => 'pdf',
                    'mime_type' => 'application/pdf',
                    'preview_supported' => true,
                    'upload_date' => optional($pay->paid_at)->toIso8601String() ?? optional($pay->created_at)->toIso8601String(),
                    'uploaded_by' => 'System',
                    'shipment_id' => $inv->shipment_id,
                    'shipment_no' => $shipmentNo,
                    'shipment_number' => $shipmentNo,
                    'booking_id' => null,
                    'booking_no' => $bookingNo,
                    'cn_no' => $inv->shipment?->waybill_number,
                    'bucket' => DocumentType::PaymentReceipt->bucket(),
                    'available' => true,
                    'source' => ['kind' => 'payment_receipt', 'id' => $pay->id],
                ]);
            }
        }

        return $out;
    }

    // ──────────────────────────────────────────────────────────────
    //  Single-document fetchers (used by show + preview/download)
    // ──────────────────────────────────────────────────────────────

    private function bookingAttachmentDocument(int $id, int $companyId): ?array
    {
        $a = BookingAttachment::query()
            ->whereIn('booking_id', Booking::query()->where('company_id', $companyId)->select('id'))
            ->with(['booking:id,booking_number,company_id', 'booking.company:id,name', 'uploader:id,name'])
            ->find($id);

        if (! $a) {
            return null;
        }

        $type = $this->attachmentDocumentType($a);

        return $this->shape(
            $type,
            $a->original_name,
            optional($a->created_at)->toIso8601String(),
            $a->uploader?->name ?? '—',
            null,
            null,
            $a->booking_id,
            $a->booking?->booking_number,
            null,
            $this->extension($a->original_name ?? $a->file_path),
            $a->mime_type,
            $this->isPreviewSupported($a->original_name ?? $a->file_path),
            [
                'document_name' => $a->original_name,
                'document_type' => $this->labelFor($type),
                'booking_no' => $a->booking?->booking_number,
                'shipment_no' => null,
                'customer' => $a->booking?->company?->name ?? null,
                'uploaded_by' => $a->uploader?->name ?? '—',
                'upload_date' => optional($a->created_at)->toIso8601String(),
                'remarks' => $a->remarks,
            ],
            [
                'kind' => $type === DocumentType::OtherSupporting ? 'other_supporting' : 'booking_attachment',
                'id' => $a->id,
                'file_path' => $a->file_path,
            ],
            null,
            null,
        );
    }

    private function consignmentNoteDocument(int $shipmentId, int $companyId): ?array
    {
        $s = Shipment::query()
            ->where('company_id', $companyId)
            ->with(['booking:id,booking_number,company_id', 'booking.company:id,name', 'originLocation:id,name,code', 'destinationLocation:id,name,code', 'serviceType:id,name,code'])
            ->find($shipmentId);

        if (! $s || empty($s->waybill_number)) {
            return null;
        }

        return $this->shape(
            DocumentType::ConsignmentNote,
            'Consignment Note '.($s->waybill_number ?? ''),
            optional($s->created_at)->toIso8601String(),
            'Operations',
            $s->id,
            $s->shipment_number,
            $s->booking_id,
            $s->booking?->booking_number,
            $s->waybill_number,
            'pdf',
            'application/pdf',
            true,
            $this->cnInfo($s),
            ['kind' => 'consignment_note', 'id' => $s->id],
            $s->id,
            $s->shipment_number,
        );
    }

    private function deliveryOrderDocument(int $shipmentId, int $companyId): ?array
    {
        $s = Shipment::query()
            ->where('company_id', $companyId)
            ->with(['booking:id,booking_number,company_id', 'booking.company:id,name', 'originLocation:id,name,code', 'destinationLocation:id,name,code', 'serviceType:id,name,code'])
            ->find($shipmentId);

        if (! $s) {
            return null;
        }

        $allowed = ['ready_for_pickup', 'arrived', 'train_arrived', 'unloading', 'container_unloading', 'completed'];
        if (! in_array(strtolower((string) $s->status), $allowed, true)) {
            return null;
        }

        return $this->shape(
            DocumentType::DeliveryOrder,
            'Delivery Order '.($s->shipment_number ?? ''),
            optional($s->updated_at)->toIso8601String() ?? optional($s->created_at)->toIso8601String(),
            'Operations',
            $s->id,
            $s->shipment_number,
            $s->booking_id,
            $s->booking?->booking_number,
            $s->waybill_number,
            'pdf',
            'application/pdf',
            true,
            $this->cnInfo($s),
            ['kind' => 'delivery_order', 'id' => $s->id],
            $s->id,
            $s->shipment_number,
        );
    }

    private function podDocument(int $photoId, int $companyId): ?array
    {
        $p = ShipmentTrackingPhoto::query()
            ->whereHas('tracking.shipment', fn ($q) => $q->where('company_id', $companyId))
            ->with(['tracking.shipment:id,shipment_number,waybill_number,booking_id', 'tracking.shipment.booking:id,booking_number,company_id'])
            ->find($photoId);

        if (! $p) {
            return null;
        }

        $s = $p->tracking?->shipment;
        $ext = $this->extension($p->path);

        return $this->shape(
            DocumentType::ProofOfDelivery,
            $p->caption ?: ('POD '.basename((string) $p->path)),
            optional($p->created_at)->toIso8601String(),
            'Operations',
            $s?->id,
            $s?->shipment_number,
            $s?->booking_id,
            $s?->booking?->booking_number,
            $s?->waybill_number,
            $ext,
            $this->mimeForExt($ext),
            $this->isPreviewSupported($p->path),
            $this->cnInfo($s) + [
                'document_name' => $p->caption ?: basename((string) $p->path),
                'document_type' => 'Proof of Delivery (POD)',
                'remarks' => null,
            ],
            ['kind' => 'proof_of_delivery', 'id' => $p->id, 'file_path' => $p->path],
            $s?->id,
            $s?->shipment_number,
        );
    }

    private function invoiceDocument(int $invoiceId, int $companyId): ?array
    {
        $inv = Invoice::query()
            ->where('company_id', $companyId)
            ->with(['company:id,name', 'shipment:id,shipment_number,waybill_number,booking_id', 'shipment.booking:id,booking_number'])
            ->find($invoiceId);

        if (! $inv) {
            return null;
        }

        return $this->shape(
            DocumentType::Invoice,
            'Invoice '.($inv->invoice_number ?? '#'.$inv->id),
            optional($inv->created_at)->toIso8601String(),
            'Finance',
            $inv->shipment_id,
            $inv->shipment?->shipment_number,
            null,
            $inv->shipment?->booking?->booking_number,
            $inv->shipment?->waybill_number,
            'pdf',
            'application/pdf',
            true,
            $this->invoiceInfo($inv),
            ['kind' => 'invoice', 'id' => $inv->id],
            $inv->shipment_id,
            $inv->shipment?->shipment_number,
        );
    }

    private function taxInvoiceDocument(int $invoiceId, int $companyId): ?array
    {
        $inv = Invoice::query()
            ->where('company_id', $companyId)
            ->where('status', 'paid')
            ->with(['company:id,name', 'shipment:id,shipment_number,waybill_number,booking_id', 'shipment.booking:id,booking_number'])
            ->find($invoiceId);

        if (! $inv) {
            return null;
        }

        return $this->shape(
            DocumentType::TaxInvoice,
            'Tax Invoice '.($inv->invoice_number ?? '#'.$inv->id),
            optional($inv->updated_at)->toIso8601String(),
            'Finance',
            $inv->shipment_id,
            $inv->shipment?->shipment_number,
            null,
            $inv->shipment?->booking?->booking_number,
            $inv->shipment?->waybill_number,
            'pdf',
            'application/pdf',
            true,
            $this->invoiceInfo($inv, isTax: true),
            ['kind' => 'tax_invoice', 'id' => $inv->id],
            $inv->shipment_id,
            $inv->shipment?->shipment_number,
        );
    }

    private function paymentReceiptDocument(int $paymentId, int $companyId): ?array
    {
        $pay = Payment::query()
            ->whereHas('invoice', fn ($q) => $q->where('company_id', $companyId))
            ->where('status', 'success')
            ->with(['invoice:id,invoice_number,total_amount,company_id,shipment_id', 'invoice.company:id,name', 'invoice.shipment:id,shipment_number,waybill_number,booking_id', 'invoice.shipment.booking:id,booking_number'])
            ->find($paymentId);

        if (! $pay) {
            return null;
        }

        $inv = $pay->invoice;

        return $this->shape(
            DocumentType::PaymentReceipt,
            'Payment Receipt '.($pay->midtrans_order_id ?? '#'.$pay->id),
            optional($pay->paid_at)->toIso8601String() ?? optional($pay->created_at)->toIso8601String(),
            'System',
            $inv?->shipment_id,
            $inv?->shipment?->shipment_number,
            null,
            $inv?->shipment?->booking?->booking_number,
            $inv?->shipment?->waybill_number,
            'pdf',
            'application/pdf',
            true,
            $this->receiptInfo($pay, $inv),
            ['kind' => 'payment_receipt', 'id' => $pay->id],
            $inv?->shipment_id,
            $inv?->shipment?->shipment_number,
        );
    }

    private function otherSupportingDocument(int $attachmentId, int $companyId): ?array
    {
        $a = BookingAttachment::query()
            ->whereIn('booking_id', Booking::query()->where('company_id', $companyId)->select('id'))
            ->with(['booking:id,booking_number,company_id', 'booking.company:id,name', 'uploader:id,name'])
            ->find($attachmentId);

        if (! $a || $this->attachmentDocumentType($a) !== DocumentType::OtherSupporting) {
            return null;
        }

        return $this->bookingAttachmentDocument($attachmentId, $companyId);
    }

    private function msdsDocument(int $virtualId, int $companyId): ?array
    {
        if ($virtualId >= self::MSDS_CONTAINER_ID_OFFSET) {
            $containerId = $virtualId - self::MSDS_CONTAINER_ID_OFFSET;
            $c = BookingContainer::query()
                ->whereNotNull('msds_file_path')
                ->whereHas('booking', fn ($q) => $q->where('company_id', $companyId))
                ->with(['booking:id,booking_number,company_id', 'booking.company:id,name'])
                ->find($containerId);

            if (! $c || empty($c->msds_file_path)) {
                return null;
            }

            $name = 'MSDS/SDS — '.($c->cargo_description ?: 'Container #'.$c->sequence);

            return $this->shape(
                DocumentType::MsdsFile,
                $name,
                optional($c->updated_at ?? $c->created_at)->toIso8601String(),
                'Customer',
                null,
                null,
                $c->booking_id,
                $c->booking?->booking_number,
                null,
                $this->extension($c->msds_file_path) ?? 'pdf',
                'application/pdf',
                $this->isPreviewSupported($c->msds_file_path),
                [
                    'document_name' => $name,
                    'document_type' => 'Booking Attachment',
                    'booking_no' => $c->booking?->booking_number,
                    'shipment_no' => null,
                    'customer' => $c->booking?->company?->name ?? null,
                    'uploaded_by' => 'Customer',
                    'upload_date' => optional($c->updated_at ?? $c->created_at)->toIso8601String(),
                    'remarks' => 'MSDS/SDS per container.',
                ],
                [
                    'kind' => 'msds_file',
                    'entity' => 'container',
                    'id' => $c->id,
                    'file_path' => $c->msds_file_path,
                ],
                null,
                null,
            );
        }

        $p = BookingPackage::query()
            ->whereNotNull('msds_file_path')
            ->whereHas('booking', fn ($q) => $q->where('company_id', $companyId))
            ->with(['booking:id,booking_number,company_id', 'booking.company:id,name'])
            ->find($virtualId);

        if (! $p || empty($p->msds_file_path)) {
            return null;
        }

        $name = 'MSDS/SDS — '.($p->description ?: 'Package #'.$p->sequence);

        return $this->shape(
            DocumentType::MsdsFile,
            $name,
            optional($p->updated_at ?? $p->created_at)->toIso8601String(),
            'Customer',
            null,
            null,
            $p->booking_id,
            $p->booking?->booking_number,
            null,
            $this->extension($p->msds_file_path) ?? 'pdf',
            'application/pdf',
            $this->isPreviewSupported($p->msds_file_path),
            [
                'document_name' => $name,
                'document_type' => 'Booking Attachment',
                'booking_no' => $p->booking?->booking_number,
                'shipment_no' => null,
                'customer' => $p->booking?->company?->name ?? null,
                'uploaded_by' => 'Customer',
                'upload_date' => optional($p->updated_at ?? $p->created_at)->toIso8601String(),
                'remarks' => 'MSDS/SDS per package.',
            ],
            [
                'kind' => 'msds_file',
                'entity' => 'package',
                'id' => $p->id,
                'file_path' => $p->msds_file_path,
            ],
            null,
            null,
        );
    }

    // ──────────────────────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────────────────────

    private function consignmentNoteRow(Shipment $s): array
    {
        return [
            'id' => DocumentType::ConsignmentNote->prefix().'-'.$s->id,
            'document_type' => DocumentType::ConsignmentNote->value,
            'document_type_label' => 'Consignment Note (CN)',
            'name' => 'Consignment Note '.($s->waybill_number ?? ''),
            'format' => 'pdf',
            'mime_type' => 'application/pdf',
            'preview_supported' => true,
            'upload_date' => optional($s->created_at)->toIso8601String(),
            'uploaded_by' => 'Operations',
            'shipment_id' => $s->id,
            'shipment_no' => $s->shipment_number,
            'shipment_number' => $s->shipment_number,
            'booking_id' => $s->booking_id,
            'booking_no' => $s->booking?->booking_number,
            'cn_no' => $s->waybill_number,
            'bucket' => DocumentType::ConsignmentNote->bucket(),
            'available' => true,
            'source' => ['kind' => 'consignment_note', 'id' => $s->id],
        ];
    }

    private function deliveryOrderRow(Shipment $s): array
    {
        return [
            'id' => DocumentType::DeliveryOrder->prefix().'-'.$s->id,
            'document_type' => DocumentType::DeliveryOrder->value,
            'document_type_label' => 'Delivery Order',
            'name' => 'Delivery Order '.($s->shipment_number ?? ''),
            'format' => 'pdf',
            'mime_type' => 'application/pdf',
            'preview_supported' => true,
            'upload_date' => optional($s->updated_at)->toIso8601String() ?? optional($s->created_at)->toIso8601String(),
            'uploaded_by' => 'Operations',
            'shipment_id' => $s->id,
            'shipment_no' => $s->shipment_number,
            'shipment_number' => $s->shipment_number,
            'booking_id' => $s->booking_id,
            'booking_no' => $s->booking?->booking_number,
            'cn_no' => $s->waybill_number,
            'bucket' => DocumentType::DeliveryOrder->bucket(),
            'available' => true,
            'source' => ['kind' => 'delivery_order', 'id' => $s->id],
        ];
    }

    private function podRow(Shipment $s, ShipmentTrackingPhoto $p): array
    {
        $ext = $this->extension($p->path);

        return [
            'id' => DocumentType::ProofOfDelivery->prefix().'-'.$p->id,
            'document_type' => DocumentType::ProofOfDelivery->value,
            'document_type_label' => 'Proof of Delivery (POD)',
            'name' => $p->caption ?: ('POD '.basename((string) $p->path)),
            'format' => $ext,
            'mime_type' => $this->mimeForExt($ext),
            'preview_supported' => $this->isPreviewSupported($p->path),
            'upload_date' => optional($p->created_at)->toIso8601String(),
            'uploaded_by' => 'Operations',
            'shipment_id' => $s->id,
            'shipment_no' => $s->shipment_number,
            'shipment_number' => $s->shipment_number,
            'booking_id' => $s->booking_id,
            'booking_no' => $s->booking?->booking_number,
            'cn_no' => $s->waybill_number,
            'bucket' => DocumentType::ProofOfDelivery->bucket(),
            'available' => true,
            'source' => ['kind' => 'proof_of_delivery', 'id' => $p->id, 'file_path' => $p->path],
        ];
    }

    private function shape(
        DocumentType $type,
        string $name,
        ?string $uploadDate,
        string $uploadedBy,
        ?int $shipmentId,
        ?string $shipmentNo,
        ?int $bookingId,
        ?string $bookingNo,
        ?string $cnNo,
        string $format,
        ?string $mimeType,
        bool $previewSupported,
        array $info,
        array $source,
        ?int $relatedShipmentId,
        ?string $relatedShipmentNo,
    ): array {
        return [
            'id' => $type->prefix().'-'.($source['id'] ?? ''),
            'document_type' => $type->value,
            'document_type_label' => $this->labelFor($type),
            'name' => $name,
            'format' => $format,
            'mime_type' => $mimeType,
            'preview_supported' => $previewSupported,
            'upload_date' => $uploadDate,
            'uploaded_by' => $uploadedBy,
            'shipment_id' => $shipmentId,
            'shipment_no' => $shipmentNo,
            'shipment_number' => $shipmentNo,
            'booking_id' => $bookingId,
            'booking_no' => $bookingNo,
            'cn_no' => $cnNo,
            'bucket' => $type->bucket(),
            'available' => true,
            'source' => $source,
            'info' => $info,
            'related_shipment' => $relatedShipmentId ? ['id' => $relatedShipmentId, 'shipment_no' => $relatedShipmentNo] : null,
        ];
    }

    private function labelFor(DocumentType $type): string
    {
        return match ($type) {
            DocumentType::BookingAttachment => 'Booking Attachment',
            DocumentType::ConsignmentNote => 'Consignment Note (CN)',
            DocumentType::DeliveryOrder => 'Delivery Order',
            DocumentType::ProofOfDelivery => 'Proof of Delivery (POD)',
            DocumentType::Invoice => 'Invoice',
            DocumentType::TaxInvoice => 'Tax Invoice',
            DocumentType::PaymentReceipt => 'Payment Receipt',
            DocumentType::OtherSupporting => 'Other Supporting Document',
            DocumentType::MsdsFile => 'MSDS / SDS',
        };
    }

    private function cnInfo(?Shipment $s): array
    {
        if (! $s) {
            return [];
        }

        return [
            'document_name' => 'Consignment Note '.($s->waybill_number ?? ''),
            'document_type' => 'Shipment Document',
            'booking_no' => $s->booking?->booking_number,
            'shipment_no' => $s->shipment_number,
            'customer' => $s->booking?->company?->name ?? null,
            'uploaded_by' => 'Operations',
            'upload_date' => optional($s->created_at)->toIso8601String(),
            'remarks' => $s->notes,
        ];
    }

    private function invoiceInfo(Invoice $inv, bool $isTax = false): array
    {
        return [
            'document_name' => ($isTax ? 'Tax Invoice ' : 'Invoice ').($inv->invoice_number ?? '#'.$inv->id),
            'document_type' => $isTax ? 'Tax Invoice' : 'Invoice',
            'booking_no' => $inv->shipment?->booking?->booking_number,
            'shipment_no' => $inv->shipment?->shipment_number,
            'customer' => $inv->company?->name,
            'uploaded_by' => 'Finance',
            'upload_date' => optional($isTax ? $inv->updated_at : $inv->created_at)->toIso8601String(),
            'remarks' => $inv->notes,
        ];
    }

    private function receiptInfo(Payment $pay, ?Invoice $inv): array
    {
        return [
            'document_name' => 'Payment Receipt '.($pay->midtrans_order_id ?? '#'.$pay->id),
            'document_type' => 'Payment Receipt',
            'booking_no' => $inv?->shipment?->booking?->booking_number,
            'shipment_no' => $inv?->shipment?->shipment_number,
            'customer' => $inv?->company?->name,
            'uploaded_by' => 'System',
            'upload_date' => optional($pay->paid_at)->toIso8601String() ?? optional($pay->created_at)->toIso8601String(),
            'remarks' => 'Payment via '.($pay->payment_type ?? 'Midtrans').'.',
        ];
    }

    private function extension(?string $name): ?string
    {
        if (! $name) {
            return null;
        }
        $name = (string) $name;
        $pos = strrpos($name, '.');
        if ($pos === false) {
            return null;
        }

        return strtolower(substr($name, $pos + 1));
    }

    private function mimeForExt(?string $ext): ?string
    {
        return match (strtolower((string) $ext)) {
            'pdf' => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => null,
        };
    }

    private function isPreviewSupported(?string $name): bool
    {
        $ext = $this->extension($name);
        if (! $ext) {
            return false;
        }

        return in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'], true);
    }
}
