<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CompanyActivity;
use App\Models\ProofOfDelivery;
use App\Models\Shipment;
use App\Models\VendorJobOrderDocument;
use App\Models\VendorProgressAttachment;
use App\Support\VendorShipmentHelper;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Aggregates vendor-visible documents from internal uploads, progress evidence,
 * and system-generated PDFs (CN/DO) for vendor portal Documents module.
 */
class VendorDocumentAggregatorService
{
    public function __construct(private readonly DocumentPdfService $pdf) {}

    public function statsForVendor(int $vendorCompanyId): array
    {
        $all = $this->collectAll($vendorCompanyId, []);

        return [
            'job_order' => $all->where('document_type', 'job_order')->count(),
            'consignment_note' => $all->where('document_type', 'consignment_note')->count(),
            'delivery_order' => $all->where('document_type', 'delivery_order')->count(),
            'proof_of_delivery' => $all->where('document_type', 'proof_of_delivery')->count(),
            'supporting' => $all->where('document_type', 'supporting_document')->count(),
        ];
    }

    /**
     * @return array{rows: array<int, array>, total: int}
     */
    public function listForVendor(int $vendorCompanyId, array $filters = []): array
    {
        $perPage = min((int) ($filters['per_page'] ?? 15), 100);
        $page = max(1, (int) ($filters['page'] ?? 1));

        $all = $this->collectAll($vendorCompanyId, $filters);

        if ($type = $filters['document_type'] ?? $filters['type'] ?? null) {
            $all = $all->filter(fn (array $r) => ($r['document_type'] ?? '') === $type);
        }

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $needle = strtolower($search);
            $all = $all->filter(function (array $r) use ($needle) {
                foreach (['name', 'jo_number', 'shipment_number'] as $key) {
                    if (str_contains(strtolower((string) ($r[$key] ?? '')), $needle)) {
                        return true;
                    }
                }

                return false;
            });
        }

        if ($serviceTypeId = (int) ($filters['service_type_id'] ?? 0)) {
            $all = $all->filter(fn (array $r) => (int) ($r['service_type_id'] ?? 0) === $serviceTypeId);
        }

        if ($dateFrom = $filters['date_from'] ?? null) {
            $all = $all->filter(fn (array $r) => ($r['upload_date'] ?? '') >= $dateFrom);
        }
        if ($dateTo = $filters['date_to'] ?? null) {
            $all = $all->filter(fn (array $r) => ($r['upload_date'] ?? '') <= $dateTo);
        }

        $all = $all->sortByDesc('upload_date')->values();
        $total = $all->count();
        $rows = $all->slice(($page - 1) * $perPage, $perPage)->values()->all();

        return ['rows' => $rows, 'total' => $total];
    }

    public function findForVendor(string $id, int $vendorCompanyId): ?array
    {
        $parts = explode('-', $id, 2);
        if (count($parts) !== 2) {
            return null;
        }

        [$prefix, $pk] = $parts;
        $pkInt = (int) $pk;

        return match ($prefix) {
            'vdoc' => $this->adminDocument($pkInt, $vendorCompanyId),
            'vprog' => $this->progressDocument($pkInt, $vendorCompanyId),
            'vpod' => $this->podDocument($pkInt, $vendorCompanyId),
            'vcn' => $this->consignmentNoteDocument($pkInt, $vendorCompanyId),
            'vdo' => $this->deliveryOrderDocument($pkInt, $vendorCompanyId),
            'vjo' => $this->jobOrderVirtualDocument($pkInt, $vendorCompanyId),
            default => null,
        };
    }

    private function collectAll(int $vendorCompanyId, array $filters): Collection
    {
        return collect()
            ->merge($this->collectAdminDocuments($vendorCompanyId))
            ->merge($this->collectProgressDocuments($vendorCompanyId))
            ->merge($this->collectPodDocuments($vendorCompanyId))
            ->merge($this->collectVirtualShipmentDocuments($vendorCompanyId));
    }

    private function shipmentIds(int $vendorCompanyId): Collection
    {
        return Shipment::forVendor($vendorCompanyId)->pluck('id');
    }

    private function collectAdminDocuments(int $vendorCompanyId): Collection
    {
        $shipmentIds = $this->shipmentIds($vendorCompanyId);

        return VendorJobOrderDocument::query()
            ->whereHas('vendorJobOrder', fn ($q) => $q->whereIn('shipment_id', $shipmentIds))
            ->with(['vendorJobOrder.shipment.serviceType', 'uploadedBy:id,name'])
            ->orderByDesc('created_at')
            ->get()
            ->map(function (VendorJobOrderDocument $doc) {
                $shipment = $doc->vendorJobOrder?->shipment;
                $type = match ($doc->document_type) {
                    'job_order_pdf', 'job_order' => 'job_order',
                    'consignment_note' => 'consignment_note',
                    'delivery_order' => 'delivery_order',
                    'proof_of_delivery', 'pod' => 'proof_of_delivery',
                    default => 'supporting_document',
                };

                return $this->normalizeRow([
                    'id' => 'vdoc-'.$doc->id,
                    'name' => $doc->original_name,
                    'document_type' => $type,
                    'document_type_label' => $this->typeLabel($type),
                    'shipment_id' => $shipment?->id,
                    'shipment_number' => $shipment?->shipment_number,
                    'jo_number' => $shipment ? VendorShipmentHelper::joNumber($shipment) : null,
                    'service_type_id' => $shipment?->service_type_id,
                    'service_type' => $shipment?->serviceType?->name,
                    'uploaded_by' => $doc->uploadedBy?->name ?? 'Internal',
                    'upload_date' => $doc->created_at?->toDateString(),
                    'mime_type' => $doc->mime_type,
                    'size' => (int) $doc->size,
                    'format' => pathinfo($doc->original_name, PATHINFO_EXTENSION) ?: 'file',
                    'source' => ['kind' => 'admin_document', 'id' => $doc->id],
                ]);
            });
    }

    private function collectProgressDocuments(int $vendorCompanyId): Collection
    {
        return VendorProgressAttachment::query()
            ->whereHas('progressUpdate.shipment', fn ($q) => $q->forVendor($vendorCompanyId))
            ->with(['progressUpdate.shipment.serviceType', 'progressUpdate.submittedByUser:id,name'])
            ->orderByDesc('created_at')
            ->get()
            ->map(function (VendorProgressAttachment $att) {
                $shipment = $att->progressUpdate?->shipment;
                $isCompletion = ! empty($att->progressUpdate?->completion_remark);
                $type = $isCompletion ? 'proof_of_delivery' : 'supporting_document';

                return $this->normalizeRow([
                    'id' => 'vprog-'.$att->id,
                    'name' => $att->original_name,
                    'document_type' => $type,
                    'document_type_label' => $this->typeLabel($type),
                    'shipment_id' => $shipment?->id,
                    'shipment_number' => $shipment?->shipment_number,
                    'jo_number' => $shipment ? VendorShipmentHelper::joNumber($shipment) : null,
                    'service_type_id' => $shipment?->service_type_id,
                    'service_type' => $shipment?->serviceType?->name,
                    'uploaded_by' => $att->progressUpdate?->submittedByUser?->name ?? 'Vendor',
                    'upload_date' => $att->created_at?->toDateString(),
                    'mime_type' => $att->mime_type,
                    'size' => (int) $att->size,
                    'format' => pathinfo($att->original_name, PATHINFO_EXTENSION) ?: 'file',
                    'file_url' => Storage::url($att->file_path),
                    'source' => ['kind' => 'progress_attachment', 'id' => $att->id],
                ]);
            });
    }

    private function collectPodDocuments(int $vendorCompanyId): Collection
    {
        return ProofOfDelivery::query()
            ->whereHas('shipment', fn ($q) => $q->forVendor($vendorCompanyId))
            ->with(['shipment.serviceType', 'submitter:id,name'])
            ->orderByDesc('updated_at')
            ->get()
            ->flatMap(function (ProofOfDelivery $pod) {
                $shipment = $pod->shipment;
                $rows = collect();

                if ($pod->signed_pod_path && Storage::disk('public')->exists($pod->signed_pod_path)) {
                    $rows->push($this->normalizeRow([
                        'id' => 'vpod-'.$pod->id,
                        'name' => 'Signed POD - '.$pod->pod_number,
                        'document_type' => 'proof_of_delivery',
                        'document_type_label' => $this->typeLabel('proof_of_delivery'),
                        'shipment_id' => $shipment?->id,
                        'shipment_number' => $shipment?->shipment_number,
                        'jo_number' => $shipment ? VendorShipmentHelper::joNumber($shipment) : null,
                        'service_type_id' => $shipment?->service_type_id,
                        'service_type' => $shipment?->serviceType?->name,
                        'uploaded_by' => $pod->submitter?->name ?? 'Internal',
                        'upload_date' => ($pod->pod_date ?? $pod->updated_at)?->toDateString(),
                        'mime_type' => 'application/pdf',
                        'size' => (int) Storage::disk('public')->size($pod->signed_pod_path),
                        'format' => 'pdf',
                        'file_url' => Storage::url($pod->signed_pod_path),
                        'source' => ['kind' => 'pod_signed', 'id' => $pod->id],
                    ]));
                }

                return $rows;
            });
    }

    private function collectVirtualShipmentDocuments(int $vendorCompanyId): Collection
    {
        $doStatuses = ['ready_for_pickup', 'arrived', 'train_arrived', 'unloading', 'container_unloading', 'proof_of_delivery', 'completed'];

        return Shipment::forVendor($vendorCompanyId)
            ->with(['serviceType', 'adminVendorJobOrders'])
            ->orderByDesc('created_at')
            ->get()
            ->flatMap(function (Shipment $s) use ($doStatuses) {
                $rows = collect();
                $joNumber = VendorShipmentHelper::joNumber($s);

                if ($s->adminVendorJobOrders->isNotEmpty() || $s->vendor_company_id) {
                    $rows->push($this->normalizeRow([
                        'id' => 'vjo-'.$s->id,
                        'name' => 'Job Order - '.$joNumber,
                        'document_type' => 'job_order',
                        'document_type_label' => $this->typeLabel('job_order'),
                        'shipment_id' => $s->id,
                        'shipment_number' => $s->shipment_number,
                        'jo_number' => $joNumber,
                        'service_type_id' => $s->service_type_id,
                        'service_type' => $s->serviceType?->name,
                        'uploaded_by' => 'Internal',
                        'upload_date' => $s->created_at?->toDateString(),
                        'mime_type' => 'application/pdf',
                        'size' => 0,
                        'format' => 'pdf',
                        'source' => ['kind' => 'job_order_virtual', 'id' => $s->id],
                    ]));
                }

                if ($s->waybill_number) {
                    $rows->push($this->normalizeRow([
                        'id' => 'vcn-'.$s->id,
                        'name' => 'Consignment Note - '.$s->waybill_number,
                        'document_type' => 'consignment_note',
                        'document_type_label' => $this->typeLabel('consignment_note'),
                        'shipment_id' => $s->id,
                        'shipment_number' => $s->shipment_number,
                        'jo_number' => $joNumber,
                        'service_type_id' => $s->service_type_id,
                        'service_type' => $s->serviceType?->name,
                        'uploaded_by' => 'Internal',
                        'upload_date' => $s->created_at?->toDateString(),
                        'mime_type' => 'application/pdf',
                        'size' => 0,
                        'format' => 'pdf',
                        'source' => ['kind' => 'consignment_note', 'id' => $s->id],
                    ]));
                }

                if (in_array($s->status, $doStatuses, true)) {
                    $rows->push($this->normalizeRow([
                        'id' => 'vdo-'.$s->id,
                        'name' => 'Delivery Order - '.$s->shipment_number,
                        'document_type' => 'delivery_order',
                        'document_type_label' => $this->typeLabel('delivery_order'),
                        'shipment_id' => $s->id,
                        'shipment_number' => $s->shipment_number,
                        'jo_number' => $joNumber,
                        'service_type_id' => $s->service_type_id,
                        'service_type' => $s->serviceType?->name,
                        'uploaded_by' => 'Internal',
                        'upload_date' => ($s->actual_arrival ?? $s->updated_at)?->toDateString(),
                        'mime_type' => 'application/pdf',
                        'size' => 0,
                        'format' => 'pdf',
                        'source' => ['kind' => 'delivery_order', 'id' => $s->id],
                    ]));
                }

                return $rows;
            });
    }

    private function adminDocument(int $id, int $vendorCompanyId): ?array
    {
        $doc = VendorJobOrderDocument::with(['vendorJobOrder.shipment.serviceType', 'uploadedBy'])->find($id);
        if (! $doc?->vendorJobOrder?->shipment) {
            return null;
        }
        if ($doc->vendorJobOrder->shipment->vendor_company_id !== $vendorCompanyId) {
            return null;
        }

        $type = match ($doc->document_type) {
            'job_order_pdf', 'job_order' => 'job_order',
            'consignment_note' => 'consignment_note',
            'delivery_order' => 'delivery_order',
            default => 'supporting_document',
        };
        $shipment = $doc->vendorJobOrder->shipment;

        return $this->normalizeRow([
            'id' => 'vdoc-'.$doc->id,
            'name' => $doc->original_name,
            'document_type' => $type,
            'document_type_label' => $this->typeLabel($type),
            'shipment_id' => $shipment->id,
            'shipment_number' => $shipment->shipment_number,
            'jo_number' => VendorShipmentHelper::joNumber($shipment),
            'uploaded_by' => $doc->uploadedBy?->name ?? 'Internal',
            'upload_date' => $doc->created_at?->toDateString(),
            'mime_type' => $doc->mime_type,
            'size' => (int) $doc->size,
            'format' => pathinfo($doc->original_name, PATHINFO_EXTENSION) ?: 'file',
            'file_url' => Storage::url($doc->file_path),
            'source' => ['kind' => 'admin_document', 'id' => $doc->id],
            'activities' => $this->documentActivities('vdoc-'.$doc->id),
        ]);
    }

    private function progressDocument(int $id, int $vendorCompanyId): ?array
    {
        $att = VendorProgressAttachment::with(['progressUpdate.shipment.serviceType', 'progressUpdate.submittedByUser'])->find($id);
        if ($att?->progressUpdate?->shipment?->vendor_company_id !== $vendorCompanyId) {
            return null;
        }
        $shipment = $att->progressUpdate->shipment;
        $isCompletion = ! empty($att->progressUpdate->completion_remark);
        $type = $isCompletion ? 'proof_of_delivery' : 'supporting_document';

        return $this->normalizeRow([
            'id' => 'vprog-'.$att->id,
            'name' => $att->original_name,
            'document_type' => $type,
            'document_type_label' => $this->typeLabel($type),
            'shipment_id' => $shipment->id,
            'shipment_number' => $shipment->shipment_number,
            'jo_number' => VendorShipmentHelper::joNumber($shipment),
            'uploaded_by' => $att->progressUpdate->submittedByUser?->name ?? 'Vendor',
            'upload_date' => $att->created_at?->toDateString(),
            'mime_type' => $att->mime_type,
            'size' => (int) $att->size,
            'format' => pathinfo($att->original_name, PATHINFO_EXTENSION) ?: 'file',
            'file_url' => Storage::url($att->file_path),
            'source' => ['kind' => 'progress_attachment', 'id' => $att->id],
            'activities' => $this->documentActivities('vprog-'.$att->id),
        ]);
    }

    private function podDocument(int $id, int $vendorCompanyId): ?array
    {
        $pod = ProofOfDelivery::with(['shipment.serviceType', 'submitter'])->find($id);
        if ($pod?->shipment?->vendor_company_id !== $vendorCompanyId) {
            return null;
        }

        return $this->normalizeRow([
            'id' => 'vpod-'.$pod->id,
            'name' => 'Signed POD - '.$pod->pod_number,
            'document_type' => 'proof_of_delivery',
            'document_type_label' => $this->typeLabel('proof_of_delivery'),
            'shipment_id' => $pod->shipment->id,
            'shipment_number' => $pod->shipment->shipment_number,
            'jo_number' => VendorShipmentHelper::joNumber($pod->shipment),
            'uploaded_by' => $pod->submitter?->name ?? 'Internal',
            'upload_date' => ($pod->pod_date ?? $pod->updated_at)?->toDateString(),
            'mime_type' => 'application/pdf',
            'size' => $pod->signed_pod_path ? (int) Storage::disk('public')->size($pod->signed_pod_path) : 0,
            'format' => 'pdf',
            'file_url' => $pod->signed_pod_path ? Storage::url($pod->signed_pod_path) : null,
            'source' => ['kind' => 'pod_signed', 'id' => $pod->id],
            'activities' => $this->documentActivities('vpod-'.$pod->id),
        ]);
    }

    private function consignmentNoteDocument(int $shipmentId, int $vendorCompanyId): ?array
    {
        $s = Shipment::with('serviceType')->find($shipmentId);
        if (! $s || $s->vendor_company_id !== $vendorCompanyId || ! $s->waybill_number) {
            return null;
        }

        return $this->normalizeRow([
            'id' => 'vcn-'.$s->id,
            'name' => 'Consignment Note - '.$s->waybill_number,
            'document_type' => 'consignment_note',
            'document_type_label' => $this->typeLabel('consignment_note'),
            'shipment_id' => $s->id,
            'shipment_number' => $s->shipment_number,
            'jo_number' => VendorShipmentHelper::joNumber($s),
            'uploaded_by' => 'Internal',
            'upload_date' => $s->created_at?->toDateString(),
            'mime_type' => 'application/pdf',
            'size' => 0,
            'format' => 'pdf',
            'source' => ['kind' => 'consignment_note', 'id' => $s->id],
            'activities' => $this->documentActivities('vcn-'.$s->id),
        ]);
    }

    private function deliveryOrderDocument(int $shipmentId, int $vendorCompanyId): ?array
    {
        $s = Shipment::with('serviceType')->find($shipmentId);
        if (! $s || $s->vendor_company_id !== $vendorCompanyId) {
            return null;
        }

        return $this->normalizeRow([
            'id' => 'vdo-'.$s->id,
            'name' => 'Delivery Order - '.$s->shipment_number,
            'document_type' => 'delivery_order',
            'document_type_label' => $this->typeLabel('delivery_order'),
            'shipment_id' => $s->id,
            'shipment_number' => $s->shipment_number,
            'jo_number' => VendorShipmentHelper::joNumber($s),
            'uploaded_by' => 'Internal',
            'upload_date' => ($s->actual_arrival ?? $s->updated_at)?->toDateString(),
            'mime_type' => 'application/pdf',
            'size' => 0,
            'format' => 'pdf',
            'source' => ['kind' => 'delivery_order', 'id' => $s->id],
            'activities' => $this->documentActivities('vdo-'.$s->id),
        ]);
    }

    private function jobOrderVirtualDocument(int $shipmentId, int $vendorCompanyId): ?array
    {
        $s = Shipment::with(['serviceType', 'adminVendorJobOrders'])->find($shipmentId);
        if (! $s || $s->vendor_company_id !== $vendorCompanyId) {
            return null;
        }

        return $this->normalizeRow([
            'id' => 'vjo-'.$s->id,
            'name' => 'Job Order - '.VendorShipmentHelper::joNumber($s),
            'document_type' => 'job_order',
            'document_type_label' => $this->typeLabel('job_order'),
            'shipment_id' => $s->id,
            'shipment_number' => $s->shipment_number,
            'jo_number' => VendorShipmentHelper::joNumber($s),
            'uploaded_by' => 'Internal',
            'upload_date' => $s->created_at?->toDateString(),
            'mime_type' => 'application/pdf',
            'size' => 0,
            'format' => 'pdf',
            'source' => ['kind' => 'job_order_virtual', 'id' => $s->id],
            'activities' => $this->documentActivities('vjo-'.$s->id),
        ]);
    }

    public function renderPdf(array $document): ?\Barryvdh\DomPDF\PDF
    {
        $source = $document['source'] ?? [];
        $kind = $source['kind'] ?? null;
        $id = (int) ($source['id'] ?? 0);

        return match ($kind) {
            'consignment_note' => $this->pdf->renderConsignmentNote(Shipment::findOrFail($id)),
            'delivery_order' => $this->pdf->renderDeliveryOrder(Shipment::findOrFail($id)),
            'job_order_virtual' => $this->pdf->renderConsignmentNote(Shipment::findOrFail($id)),
            default => null,
        };
    }

    public function logDownload(int $vendorCompanyId, string $documentId, int $userId): void
    {
        CompanyActivity::create([
            'subject_type' => CompanyActivity::class,
            'subject_id' => $vendorCompanyId,
            'event_key' => 'vendor_document_downloaded',
            'description' => 'Dokumen '.$documentId.' diunduh oleh vendor.',
            'meta' => ['document_id' => $documentId],
            'actor_user_id' => $userId,
            'occurred_at' => now(),
        ]);
    }

    private function documentActivities(string $documentId): array
    {
        return CompanyActivity::query()
            ->where('event_key', 'vendor_document_downloaded')
            ->where('meta->document_id', $documentId)
            ->with('actor:id,name')
            ->orderByDesc('occurred_at')
            ->limit(10)
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'description' => $a->description,
                'actor_name' => $a->actor?->name,
                'occurred_at' => $a->occurred_at?->toIso8601String(),
            ])
            ->all();
    }

    private function normalizeRow(array $row): array
    {
        return array_merge([
            'type' => $row['document_type'] ?? null,
            'type_label' => $row['document_type_label'] ?? null,
            'uploaded_at' => $row['upload_date'] ?? null,
        ], $row);
    }

    private function typeLabel(string $type): string
    {
        return match ($type) {
            'job_order' => 'Job Order',
            'consignment_note' => 'Consignment Note (CN)',
            'delivery_order' => 'Delivery Order (DO)',
            'proof_of_delivery' => 'Proof of Delivery (POD)',
            'supporting_document' => 'Supporting Document',
            default => ucfirst(str_replace('_', ' ', $type)),
        };
    }
}
