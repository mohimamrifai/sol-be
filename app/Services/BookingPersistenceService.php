<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\CargoCategory;
use App\Models\ServiceType;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

final class BookingPersistenceService
{
    /**
     * @param  list<string>  $keys
     */
    public function mergeJsonFields(Request $request, array $keys = ['additional_services', 'packages', 'containers', 'shipper_snapshot', 'consignee_snapshot', 'attachments_meta']): void
    {
        foreach ($keys as $key) {
            if (is_string($request->input($key))) {
                $decoded = json_decode($request->input($key), true);
                if (is_array($decoded)) {
                    $request->merge([$key => $decoded]);
                }
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function cargoFieldRules(bool $isDraft): array
    {
        return [
            'container_responsibility' => 'nullable|in:SOC,COC',
            'consignee_type' => $isDraft ? 'nullable|in:customer_location,external' : 'nullable|in:customer_location,external',
            'consignee_branch_id' => 'nullable|integer|exists:branches,id',
            'shipper_branch_id' => 'nullable|integer|exists:branches,id',
            'shipper_snapshot' => 'nullable|array',
            'consignee_snapshot' => 'nullable|array',
            'delivery_notes' => 'nullable|string',
            'packages' => 'nullable|array',
            'packages.*.description' => 'nullable|string|max:500',
            'packages.*.length' => 'nullable|numeric|min:0',
            'packages.*.width' => 'nullable|numeric|min:0',
            'packages.*.height' => 'nullable|numeric|min:0',
            'packages.*.weight_kg' => 'nullable|numeric|min:0',
            'packages.*.piece_count' => 'nullable|integer|min:1',
            'packages.*.package_type' => 'nullable|string|max:80',
            'packages.*.remark' => 'nullable|string',
            'packages.*.is_dangerous_goods' => 'nullable|boolean',
            'packages.*.dg_class_id' => 'nullable|exists:dg_classes,id',
            'packages.*.un_number' => 'nullable|string|max:50',
            'packages_msds_files' => 'nullable|array',
            'packages_msds_files.*' => 'nullable|file|mimes:pdf|max:5120',
            'containers' => 'nullable|array',
            'containers.*.container_type_id' => 'nullable|exists:container_types,id',
            'containers.*.quantity' => 'nullable|integer|min:1',
            'containers.*.container_number' => 'nullable|string|max:20',
            'containers.*.seal_number' => 'nullable|string|max:50',
            'containers.*.gross_weight_kg' => 'nullable|numeric|min:0',
            'containers.*.volume_cbm' => 'nullable|numeric|min:0',
            'containers.*.cargo_description' => 'nullable|string|max:500',
            'containers.*.remark' => 'nullable|string',
            'containers.*.equipment_condition' => 'nullable|in:CLEAN,RESIDUAL',
            'containers.*.temperature' => 'nullable|numeric',
            'containers.*.is_dangerous_goods' => 'nullable|boolean',
            'containers.*.dg_class_id' => 'nullable|exists:dg_classes,id',
            'containers.*.un_number' => 'nullable|string|max:50',
            'containers_msds_files' => 'nullable|array',
            'containers_msds_files.*' => 'nullable|file|mimes:pdf|max:5120',
            'attachments' => 'nullable|array',
            'attachments.*' => 'file|mimes:pdf,jpg,jpeg,png,xlsx|max:10240',
            'attachments_meta' => 'nullable|array',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, list<string>>|null
     */
    public function validateCargoRules(Request $request, array $data, bool $isDraft, ?int $companyId = null): ?array
    {
        if ($isDraft) {
            return null;
        }

        $errors = [];

        $coverage = $data['shipment_coverage'] ?? null;
        if (in_array($coverage, ['door_to_port', 'door_to_door'], true)) {
            if (empty($data['pickup_date'])) {
                $errors['pickup_date'][] = 'Preferred Pickup Date wajib diisi.';
            }
            if (empty($data['pickup_time'])) {
                $errors['pickup_time'][] = 'Preferred Pickup Time wajib diisi.';
            }
        }

        if (($data['consignee_type'] ?? null) === 'customer_location' && empty($data['consignee_branch_id'])) {
            $errors['consignee_branch_id'][] = 'Customer Location wajib dipilih.';
        }

        if ($companyId && ! empty($data['shipper_branch_id'])) {
            if (Branch::where('id', $data['shipper_branch_id'])->where('company_id', $companyId)->doesntExist()) {
                $errors['shipper_branch_id'][] = 'Customer Location tidak ditemukan.';
            }
        }
        if ($companyId && ! empty($data['consignee_branch_id'])) {
            if (Branch::where('id', $data['consignee_branch_id'])->where('company_id', $companyId)->doesntExist()) {
                $errors['consignee_branch_id'][] = 'Customer Location tidak ditemukan.';
            }
        }

        $serviceType = ServiceType::find($data['service_type_id'] ?? null);
        $serviceCode = strtoupper((string) ($serviceType?->code ?? ''));

        if ($serviceCode === 'LCL') {
            if (empty($data['packages']) || ! is_array($data['packages'])) {
                $errors['packages'][] = 'Package detail wajib diisi untuk service type LCL.';
            }
        }
        if ($serviceCode === 'FCL') {
            if (empty($data['containers']) || ! is_array($data['containers'])) {
                $errors['containers'][] = 'Container detail wajib diisi untuk service type FCL.';
            }
            if (empty($data['container_responsibility'])) {
                $errors['container_responsibility'][] = 'Container Responsibility wajib dipilih.';
            }
        }

        if (! empty($data['packages']) && is_array($data['packages'])) {
            foreach ($data['packages'] as $i => $pkg) {
                if (empty($pkg['description'])) {
                    $errors["packages.$i.description"][] = 'Package Description wajib diisi.';
                }
                if (empty($pkg['piece_count'])) {
                    $errors["packages.$i.piece_count"][] = 'Quantity wajib diisi.';
                }
            }
        }

        if (! empty($data['containers']) && is_array($data['containers'])) {
            foreach ($data['containers'] as $i => $ctr) {
                if (empty($ctr['container_type_id'])) {
                    $errors["containers.$i.container_type_id"][] = 'Container Type wajib diisi.';
                }
                if (empty($ctr['quantity'])) {
                    $errors["containers.$i.quantity"][] = 'Quantity wajib diisi.';
                }
                if (empty($ctr['cargo_description'])) {
                    $errors["containers.$i.cargo_description"][] = 'Cargo Description wajib diisi.';
                }
            }
        }

        $cat = CargoCategory::find($data['cargo_category_id'] ?? null);
        if ($cat && $cat->requires_temperature && ($data['temperature'] ?? null) === null) {
            $errors['temperature'][] = 'Suhu (temperature) wajib diisi untuk kategori kargo ini.';
        }

        return $errors === [] ? null : $errors;
    }

    /**
     * @param  list<array<string, mixed>>  $packages
     */
    public function syncPackages(Booking $booking, Request $request, array $packages, bool $replace = true): void
    {
        if ($replace) {
            $booking->packages()->delete();
        }

        $sequence = 1;
        foreach ($packages as $i => $pkg) {
            $msdsItem = $request->file("packages_msds_files.$i");
            $pkgMsds = $msdsItem instanceof UploadedFile ? $msdsItem->store('msds_files', 'public') : null;

            $booking->packages()->create([
                'sequence' => $sequence++,
                'description' => $pkg['description'] ?? null,
                'length' => $pkg['length'] ?? null,
                'width' => $pkg['width'] ?? null,
                'height' => $pkg['height'] ?? null,
                'weight_kg' => $pkg['weight_kg'] ?? null,
                'volume_cbm' => $this->calcPackageCbm($pkg),
                'piece_count' => $pkg['piece_count'] ?? 1,
                'package_type' => $pkg['package_type'] ?? null,
                'remark' => $pkg['remark'] ?? null,
                'is_dangerous_goods' => $pkg['is_dangerous_goods'] ?? false,
                'dg_class_id' => $pkg['dg_class_id'] ?? null,
                'un_number' => $pkg['un_number'] ?? null,
                'packing_group' => $pkg['packing_group'] ?? null,
                'proper_shipping_name' => $pkg['proper_shipping_name'] ?? null,
                'flash_point' => $pkg['flash_point'] ?? null,
                'msds_file_path' => $pkgMsds,
                'dg_notes' => $pkg['dg_notes'] ?? null,
                'dg_remark' => $pkg['dg_remark'] ?? null,
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $containers
     */
    public function syncContainers(Booking $booking, Request $request, array $containers, bool $replace = true): void
    {
        if ($replace) {
            $booking->containers()->delete();
        }

        $sequence = 1;
        foreach ($containers as $i => $ctr) {
            $msdsItem = $request->file("containers_msds_files.$i");
            $ctrMsds = $msdsItem instanceof UploadedFile ? $msdsItem->store('msds_files', 'public') : null;

            $booking->containers()->create([
                'sequence' => $sequence++,
                'container_type_id' => $ctr['container_type_id'] ?? null,
                'quantity' => $ctr['quantity'] ?? 1,
                'container_number' => $ctr['container_number'] ?? null,
                'seal_number' => $ctr['seal_number'] ?? null,
                'gross_weight_kg' => $ctr['gross_weight_kg'] ?? null,
                'volume_cbm' => $ctr['volume_cbm'] ?? null,
                'cargo_description' => $ctr['cargo_description'] ?? null,
                'remark' => $ctr['remark'] ?? null,
                'equipment_condition' => $ctr['equipment_condition'] ?? null,
                'temperature' => $ctr['temperature'] ?? null,
                'is_dangerous_goods' => $ctr['is_dangerous_goods'] ?? false,
                'dg_class_id' => $ctr['dg_class_id'] ?? null,
                'un_number' => $ctr['un_number'] ?? null,
                'packing_group' => $ctr['packing_group'] ?? null,
                'proper_shipping_name' => $ctr['proper_shipping_name'] ?? null,
                'flash_point' => $ctr['flash_point'] ?? null,
                'msds_file_path' => $ctrMsds,
                'dg_notes' => $ctr['dg_notes'] ?? null,
                'dg_remark' => $ctr['dg_remark'] ?? null,
            ]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $attachmentsMeta
     */
    public function syncAttachments(Booking $booking, Request $request, int $userId, array $attachmentsMeta = []): void
    {
        $files = $request->file('attachments');
        if (! is_array($files)) {
            return;
        }

        foreach ($files as $i => $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }
            $path = $file->store('booking_attachments', 'public');
            $rowMeta = is_array($attachmentsMeta[$i] ?? null) ? $attachmentsMeta[$i] : [];

            $booking->attachments()->create([
                'uploaded_by' => $userId,
                'file_path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
                'category' => 'general',
                'document_type' => $rowMeta['document_type'] ?? null,
                'remarks' => $rowMeta['remarks'] ?? null,
            ]);
        }
    }

    public function recalculateAndSave(Booking $booking): void
    {
        $booking->recalculateCargoMetrics();
        $booking->save();
    }

    /**
     * @param  array<string, mixed>  $pkg
     */
    public function calcPackageCbm(array $pkg): ?float
    {
        $l = (float) ($pkg['length'] ?? 0);
        $w = (float) ($pkg['width'] ?? 0);
        $h = (float) ($pkg['height'] ?? 0);
        $qty = (int) ($pkg['piece_count'] ?? 1);

        return $l > 0 && $w > 0 && $h > 0
            ? round((($l * $w * $h) / 1_000_000) * max($qty, 1), 4)
            : null;
    }
}
