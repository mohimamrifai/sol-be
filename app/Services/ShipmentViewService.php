<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Models\Shipment;

/**
 * Builds the customer-facing view of a Shipment (prompt.md L57-161).
 * Centralises:
 *   - documents availability map (L138-147)
 *   - cargo snapshot pulled from the related Booking (L102-118)
 *   - activity log aggregated from trackings + booking activities (L149-162)
 */
class ShipmentViewService
{
    public function documents(Shipment $shipment): array
    {
        $raw = strtolower((string) $shipment->status);
        $cnAvailable = $shipment->waybill_number !== null;

        $podAvailable = in_array($raw, ['completed', 'ready_for_pickup'], true);
        $deliveryOrderAvailable = in_array($raw, ['ready_for_pickup', 'arrived', 'train_arrived', 'unloading', 'container_unloading', 'completed'], true);

        $invoice = $shipment->invoice;
        $invoiceAvailable = $invoice !== null;
        $taxInvoiceAvailable = $invoiceAvailable && strtolower((string) $invoice->status) === 'paid';

        $podPhoto = $shipment->trackings
            ->flatMap(fn ($t) => $t->photos ?? collect())
            ->first();

        $trackingPhotos = $shipment->trackings
            ->flatMap(fn ($t) => $t->photos ?? collect())
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->caption ?? basename((string) $p->path),
                'path' => $p->path,
                'document_id' => DocumentType::ProofOfDelivery->prefix().'-'.$p->id,
            ])
            ->values()
            ->all();

        $bookingAttachments = $shipment->booking
            ? $shipment->booking->attachments()->orderBy('created_at')->get()->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->original_name,
                'path' => $a->file_path,
                'category' => $a->category,
                'document_type' => $a->document_type,
                'remarks' => $a->remarks,
                'document_id' => DocumentType::BookingAttachment->prefix().'-'.$a->id,
            ])->all()
            : [];

        $otherItems = array_merge($trackingPhotos, $bookingAttachments);

        return [
            $this->doc(
                'consignment_note',
                'Consignment Note (CN)',
                $cnAvailable,
                true,
                $shipment->id,
                [],
                $cnAvailable ? DocumentType::ConsignmentNote->prefix().'-'.$shipment->id : null,
            ),
            $this->doc(
                'pod',
                'Proof of Delivery (POD)',
                $podAvailable && $podPhoto !== null,
                true,
                $podPhoto?->id,
                [],
                $podPhoto ? DocumentType::ProofOfDelivery->prefix().'-'.$podPhoto->id : null,
            ),
            $this->doc(
                'delivery_order',
                'Delivery Order',
                $deliveryOrderAvailable,
                true,
                $shipment->id,
                [],
                $deliveryOrderAvailable ? DocumentType::DeliveryOrder->prefix().'-'.$shipment->id : null,
            ),
            $this->doc(
                'invoice',
                'Invoice',
                $invoiceAvailable,
                true,
                $invoice?->id,
                [],
                $invoiceAvailable ? DocumentType::Invoice->prefix().'-'.$invoice->id : null,
            ),
            $this->doc(
                'tax_invoice',
                'Tax Invoice',
                $taxInvoiceAvailable,
                true,
                $invoice?->id,
                [],
                $taxInvoiceAvailable ? DocumentType::TaxInvoice->prefix().'-'.$invoice->id : null,
            ),
            $this->doc(
                'other',
                'Other Supporting Documents',
                count($otherItems) > 0,
                true,
                null,
                $otherItems,
            ),
        ];
    }

    private function doc(
        string $key,
        string $label,
        bool $available,
        bool $hasEndpoint = true,
        ?int $refId = null,
        array $items = [],
        ?string $documentId = null,
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'available' => $available,
            'has_endpoint' => $hasEndpoint,
            'reference_id' => $refId,
            'document_id' => $documentId,
            'items' => $items,
        ];
    }

    public function cargo(Shipment $shipment): array
    {
        if (is_array($shipment->cargo_snapshot) && $shipment->cargo_snapshot !== []) {
            return $shipment->cargo_snapshot;
        }

        return $this->buildCargoFromBooking($shipment);
    }

    public function buildCargoFromBooking(Shipment $shipment): array
    {
        $booking = $shipment->booking;
        if (! $booking) {
            return ['service_kind' => null, 'packages' => [], 'containers' => [], 'summary' => null];
        }

        $booking->loadMissing(['packages.cargoCategory', 'containers.containerType', 'serviceType']);
        $shipment->loadMissing('serviceType');

        $serviceCode = strtoupper((string) ($shipment->serviceType?->code ?? $booking->serviceType?->code ?? ''));
        $isFcl = $serviceCode === 'FCL' || $serviceCode === 'FTL';
        $kind = $isFcl ? 'FCL' : 'LCL';

        $packages = $booking->packages()->with('cargoCategory')->orderBy('sequence')->get()->map(fn ($p) => [
            'id' => $p->id,
            'sequence' => $p->sequence,
            'description' => $p->description,
            'package_type' => $p->package_type,
            'piece_count' => (int) ($p->piece_count ?? 1),
            'weight_kg' => (float) $p->weight_kg,
            'volume_cbm' => (float) $p->volume_cbm,
            'chargeable_weight_kg' => max((float) $p->weight_kg, (float) ($p->volume_cbm ?? 0) * 1000),
            'length' => $p->length,
            'width' => $p->width,
            'height' => $p->height,
            'cargo_category' => $p->cargoCategory?->name ?? $p->cargoCategory?->code,
            'is_dangerous_goods' => (bool) $p->is_dangerous_goods,
        ])->all();

        $containers = $booking->containers()->with(['containerType', 'cargoCategory'])->orderBy('sequence')->get()->map(fn ($c) => [
            'id' => $c->id,
            'sequence' => $c->sequence,
            'container_type' => $c->containerType?->name ?? $c->containerType?->size,
            'container_number' => $c->container_number,
            'seal_number' => $c->seal_number,
            'quantity' => (int) ($c->quantity ?? 1),
            'cargo_weight_kg' => (float) $c->gross_weight_kg,
            'volume_cbm' => (float) $c->volume_cbm,
            'cargo_description' => $c->cargo_description,
            'cargo_category' => $c->cargoCategory?->name ?? $c->cargoCategory?->code,
            'container_responsibility' => $booking->container_responsibility,
            'is_dangerous_goods' => (bool) $c->is_dangerous_goods,
        ])->all();

        $summary = [
            'total_packages' => count($packages),
            'total_pieces' => array_sum(array_column($packages, 'piece_count')),
            'total_actual_weight_kg' => array_sum(array_column($packages, 'weight_kg')),
            'total_volume_cbm' => array_sum(array_column($packages, 'volume_cbm')),
            'total_chargeable_weight_kg' => (float) ($booking->chargeable_weight_kg ?? 0),
            'total_containers' => array_sum(array_column($containers, 'quantity')),
        ];

        return [
            'service_kind' => $kind,
            'service_code' => $serviceCode,
            'packages' => $packages,
            'containers' => $containers,
            'summary' => $summary,
        ];
    }

    /**
     * Admin FSD §3.16 document grid.
     *
     * @return array<int, array<string, mixed>>
     */
    public function adminDocuments(Shipment $shipment): array
    {
        $shipment->loadMissing(['documents.uploadedByUser:id,name']);

        $supporting = $shipment->documents->map(fn ($doc) => [
            'id' => $doc->id,
            'name' => $doc->original_name,
            'uploaded_by' => $doc->uploadedByUser?->name,
            'uploaded_at' => $doc->created_at?->toIso8601String(),
            'mime_type' => $doc->mime_type,
            'size' => $doc->size,
        ])->values()->all();

        return [
            [
                'key' => 'consignment_note',
                'label' => 'Consignment Note',
                'available' => $shipment->waybill_number !== null,
                'uploaded_by' => null,
                'uploaded_at' => null,
            ],
            [
                'key' => 'supporting',
                'label' => 'Supporting Documents',
                'available' => true,
                'items' => $supporting,
            ],
        ];
    }

    public function trackingTimeline(Shipment $shipment): array
    {
        return $shipment->trackings()
            ->with('updatedByUser:id,name')
            ->orderBy('tracked_at')
            ->get()
            ->map(fn ($t) => [
                'occurred_at' => optional($t->tracked_at)->toIso8601String(),
                'title' => $this->trackingTitle((string) $t->status),
                'description' => $t->notes,
                'location' => $t->location,
                'status' => $t->status,
                'actor_name' => $t->updatedByUser?->name ?? 'System',
            ])
            ->all();
    }

    public function activityLog(Shipment $shipment): array
    {
        $entries = [];

        foreach ($shipment->activities()->with('actorUser:id,name')->orderBy('occurred_at')->get() as $a) {
            $entries[] = [
                'occurred_at' => optional($a->occurred_at)->toIso8601String(),
                'title' => $a->description,
                'description' => null,
                'location' => null,
                'source' => 'shipment',
                'status' => $a->event_key,
                'actor_name' => $a->actorUser?->name,
            ];
        }

        foreach ($shipment->trackings()->with('updatedByUser:id,name')->orderBy('tracked_at')->get() as $t) {
            $entries[] = [
                'occurred_at' => optional($t->tracked_at)->toIso8601String(),
                'title' => 'Tracking diperbarui: '.$this->trackingTitle((string) $t->status),
                'description' => $t->notes,
                'location' => $t->location,
                'source' => 'tracking',
                'status' => $t->status,
                'actor_name' => $t->updatedByUser?->name ?? 'System',
            ];
        }

        usort($entries, fn ($a, $b) => strcmp((string) ($a['occurred_at'] ?? ''), (string) ($b['occurred_at'] ?? '')));

        return $entries;
    }

    public function trackingTimelineTitle(string $status): string
    {
        return $this->trackingTitle($status);
    }

    private function trackingTitle(string $status): string
    {
        $map = [
            'shipment_created' => 'Shipment Created',
            'pickup_in_progress' => 'Pickup In Progress',
            'delivery_in_progress' => 'Delivery In Progress',
            'pickup_completed' => 'Pickup Completed',
            'arrived_origin_yard' => 'Arrived Origin Yard',
            'gate_in_origin' => 'Gate In Origin',
            'loaded_to_train' => 'Loaded to Train',
            'train_departed' => 'Train Departed',
            'train_arrived' => 'Train Arrived',
            'gate_out_destination' => 'Gate Out Destination',
            'container_released' => 'Container Released',
            'out_for_delivery' => 'Out for Delivery',
            'delivered' => 'Delivered',
            'pod_uploaded' => 'POD Uploaded',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            'booking_created' => 'Booking dibuat',
            'shipment_created' => 'Shipment Created',
            'created' => 'Shipment Created',
            'survey_completed' => 'Survey selesai',
            'cargo_received' => 'Kargo diterima di origin',
            'stuffing_container' => 'Stuffing kontainer selesai',
            'container_sealed' => 'Kontainer disegel',
            'departed' => 'Shipment berangkat',
            'arrived' => 'Shipment tiba',
            'container_unloading' => 'Bongkar kontainer selesai',
            'unloading' => 'Bongkar muat selesai',
            'ready_for_pickup' => 'Ready for Departure',
            'proof_of_delivery' => 'Proof of Delivery',
        ];

        return $map[$status] ?? ucwords(str_replace('_', ' ', $status));
    }
}
