<?php

namespace App\Services;

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
        $hl = $shipment->high_level_status;
        $raw = strtolower((string) $shipment->status);

        $consignmentNote = $this->doc('consignment_note', 'Consignment Note (CN)', true, $shipment->waybill_number !== null);

        $podAvailable = in_array($raw, ['completed', 'ready_for_pickup'], true);
        $deliveryOrderAvailable = in_array($raw, ['ready_for_pickup', 'arrived', 'train_arrived', 'unloading', 'container_unloading', 'completed'], true);

        $invoice = $shipment->invoice;
        $invoiceAvailable = $invoice !== null;
        $taxInvoiceAvailable = $invoiceAvailable && strtolower((string) $invoice->status) === 'paid';

        $otherDocs = $shipment->trackings
            ->flatMap(fn ($t) => $t->photos ?? collect())
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name ?? basename((string) $p->path),
                'path' => $p->path,
                'url' => method_exists($p, 'getAttribute') ? $p->getAttribute('url') : null,
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
            ])->all()
            : [];

        return [
            $consignmentNote,
            $this->doc('pod', 'Proof of Delivery (POD)', $podAvailable),
            $this->doc('delivery_order', 'Delivery Order', $deliveryOrderAvailable),
            $this->doc('invoice', 'Invoice', $invoiceAvailable, true, $invoice?->id),
            $this->doc('tax_invoice', 'Tax Invoice', $taxInvoiceAvailable, true, $invoice?->id),
            $this->doc('other', 'Other Supporting Documents', count($otherDocs) > 0 || count($bookingAttachments) > 0, true, null, array_merge($otherDocs, $bookingAttachments)),
        ];
    }

    private function doc(string $key, string $label, bool $available, bool $hasEndpoint = true, ?int $refId = null, array $items = []): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'available' => $available,
            'has_endpoint' => $hasEndpoint,
            'reference_id' => $refId,
            'items' => $items,
        ];
    }

    public function cargo(Shipment $shipment): array
    {
        $booking = $shipment->booking;
        if (! $booking) {
            return ['service_kind' => null, 'packages' => [], 'containers' => [], 'summary' => null];
        }

        $serviceCode = strtoupper((string) ($shipment->serviceType?->code ?? $booking->serviceType?->code ?? ''));
        $isFcl = $serviceCode === 'FCL' || $serviceCode === 'FTL';
        $kind = $isFcl ? 'FCL' : 'LCL';

        $packages = $booking->packages()->orderBy('sequence')->get()->map(fn ($p) => [
            'id' => $p->id,
            'sequence' => $p->sequence,
            'description' => $p->description,
            'package_type' => $p->package_type,
            'piece_count' => (int) ($p->piece_count ?? 1),
            'weight_kg' => (float) $p->weight_kg,
            'volume_cbm' => (float) $p->volume_cbm,
            'length' => $p->length,
            'width' => $p->width,
            'height' => $p->height,
            'is_dangerous_goods' => (bool) $p->is_dangerous_goods,
        ])->all();

        $containers = $booking->containers()->orderBy('sequence')->get()->map(fn ($c) => [
            'id' => $c->id,
            'sequence' => $c->sequence,
            'container_type' => $c->containerType?->name ?? $c->containerType?->size,
            'container_number' => $c->container_number,
            'seal_number' => $c->seal_number,
            'quantity' => (int) ($c->quantity ?? 1),
            'cargo_weight_kg' => (float) $c->gross_weight_kg,
            'volume_cbm' => (float) $c->volume_cbm,
            'cargo_description' => $c->cargo_description,
            'cargo_category' => $booking->cargoCategory?->name ?? $booking->cargoCategory?->code,
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

    public function activityLog(Shipment $shipment): array
    {
        $entries = [];

        foreach ($shipment->trackings()->orderBy('tracked_at')->get() as $t) {
            $entries[] = [
                'occurred_at' => optional($t->tracked_at)->toIso8601String(),
                'title' => $this->trackingTitle((string) $t->status),
                'description' => $t->notes,
                'location' => $t->location,
                'source' => 'tracking',
                'status' => $t->status,
                'actor_name' => $t->updatedByUser?->name,
            ];
        }

        if ($shipment->booking) {
            foreach ($shipment->booking->activities()->orderBy('occurred_at')->get() as $a) {
                $entries[] = [
                    'occurred_at' => optional($a->occurred_at)->toIso8601String(),
                    'title' => $a->title,
                    'description' => $a->description,
                    'location' => null,
                    'source' => 'booking',
                    'status' => $a->activity_type,
                    'actor_name' => $a->actor?->name,
                ];
            }
        }

        usort($entries, fn ($a, $b) => strcmp((string) ($a['occurred_at'] ?? ''), (string) ($b['occurred_at'] ?? '')));

        return $entries;
    }

    private function trackingTitle(string $status): string
    {
        $map = [
            'booking_created' => 'Booking dibuat',
            'created' => 'Shipment dibuat',
            'survey_completed' => 'Survey selesai',
            'cargo_received' => 'Kargo diterima di origin',
            'stuffing_container' => 'Stuffing kontainer selesai',
            'container_sealed' => 'Kontainer disegel',
            'train_departed' => 'Kereta berangkat',
            'departed' => 'Shipment berangkat',
            'train_arrived' => 'Kereta tiba di destination',
            'arrived' => 'Shipment tiba',
            'container_unloading' => 'Bongkar kontainer selesai',
            'unloading' => 'Bongkar muat selesai',
            'ready_for_pickup' => 'Siap diambil customer',
            'completed' => 'Shipment selesai',
            'cancelled' => 'Shipment dibatalkan',
        ];

        return $map[$status] ?? ucwords(str_replace('_', ' ', $status));
    }
}
