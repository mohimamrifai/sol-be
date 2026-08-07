<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Vendor;

use App\Http\Controllers\Controller;
use App\Models\Shipment;
use App\Models\VendorProgressAttachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class DocumentController extends Controller
{
    public function stats(Request $request): JsonResponse
    {
        $vendorCompanyId = $request->user()->company_id;
        $shipments = Shipment::forVendor($vendorCompanyId)->pluck('id');

        $progressAttachments = VendorProgressAttachment::whereHas('progressUpdate', fn ($q) => $q->whereIn('shipment_id', $shipments))->count();

        return response()->json([
            'data' => [
                'job_order' => $shipments->count(),
                'consignment_note' => $shipments->count(),
                'delivery_order' => 0,
                'proof_of_completion' => $progressAttachments,
                'supporting' => $progressAttachments,
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $vendorCompanyId = $request->user()->company_id;
        $query = VendorProgressAttachment::query()
            ->whereHas('progressUpdate.shipment', fn ($q) => $q->forVendor($vendorCompanyId))
            ->with(['progressUpdate.shipment.company:id,name,company_code', 'progressUpdate.submittedByUser:id,name']);

        if ($search = $request->string('search')->toString()) {
            $query->where(function ($q) use ($search) {
                $q->where('original_name', 'like', "%{$search}%")
                    ->orWhereHas('progressUpdate.shipment', fn ($s) => $s->where('shipment_number', 'like', "%{$search}%"));
            });
        }

        $page = $query->orderByDesc('id')->paginate(min((int) $request->integer('per_page', 15) ?: 15, 100));

        $items = $page->getCollection()->map(fn ($a) => [
            'id' => $a->id,
            'name' => $a->original_name,
            'type' => 'proof_of_completion',
            'type_label' => 'Proof of Completion',
            'mime_type' => $a->mime_type,
            'size' => (int) $a->size,
            'shipment_id' => $a->progressUpdate?->shipment?->id,
            'shipment_number' => $a->progressUpdate?->shipment?->shipment_number,
            'jo_number' => $a->progressUpdate?->shipment ? 'JO-'.str_pad((string) $a->progressUpdate->shipment->id, 5, '0', STR_PAD_LEFT) : null,
            'uploaded_by' => $a->progressUpdate?->submittedByUser?->name,
            'uploaded_at' => $a->created_at?->toIso8601String(),
        ]);

        return response()->json([
            'data' => $items,
            'meta' => [
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, VendorProgressAttachment $attachment): JsonResponse
    {
        $vendorCompanyId = $request->user()->company_id;
        $attachment->load(['progressUpdate.shipment.company']);
        if ($attachment->progressUpdate?->shipment?->vendor_company_id !== $vendorCompanyId) {
            return response()->json(['message' => 'Resource tidak ditemukan.'], 404);
        }

        return response()->json(['data' => [
            'id' => $attachment->id,
            'name' => $attachment->original_name,
            'mime_type' => $attachment->mime_type,
            'size' => (int) $attachment->size,
            'uploaded_at' => $attachment->created_at?->toIso8601String(),
            'shipment_id' => $attachment->progressUpdate?->shipment?->id,
            'shipment_number' => $attachment->progressUpdate?->shipment?->shipment_number,
            'jo_number' => $attachment->progressUpdate?->shipment ? 'JO-'.str_pad((string) $attachment->progressUpdate->shipment->id, 5, '0', STR_PAD_LEFT) : null,
            'customer_name' => $attachment->progressUpdate?->shipment?->company?->name,
            'file_url' => Storage::url($attachment->file_path),
        ]]);
    }

    public function download(Request $request, VendorProgressAttachment $attachment): Response
    {
        $vendorCompanyId = $request->user()->company_id;
        $attachment->load('progressUpdate.shipment');
        if ($attachment->progressUpdate?->shipment?->vendor_company_id !== $vendorCompanyId) {
            return response()->json(['message' => 'Resource tidak ditemukan.'], 404);
        }
        if (! Storage::disk('public')->exists($attachment->file_path)) {
            return response()->json(['message' => 'File tidak ditemukan.'], 404);
        }

        $content = Storage::disk('public')->get($attachment->file_path);

        return response($content, 200, [
            'Content-Type' => $attachment->mime_type ?: 'application/octet-stream',
            'Content-Length' => (string) strlen($content),
            'Content-Disposition' => 'attachment; filename="'.addslashes($attachment->original_name).'"',
        ]);
    }
}
