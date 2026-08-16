<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Vendor;

use App\Http\Controllers\Controller;
use App\Models\VendorJobOrderDocument;
use App\Models\VendorProgressAttachment;
use App\Services\VendorDocumentAggregatorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class DocumentController extends Controller
{
    public function __construct(private readonly VendorDocumentAggregatorService $aggregator) {}

    public function stats(Request $request): JsonResponse
    {
        $vendorCompanyId = (int) $request->user()->company_id;

        return response()->json(['data' => $this->aggregator->statsForVendor($vendorCompanyId)]);
    }

    public function index(Request $request): JsonResponse
    {
        $vendorCompanyId = (int) $request->user()->company_id;
        $filters = $request->only([
            'search', 'document_type', 'type', 'service_type_id',
            'date_from', 'date_to', 'page', 'per_page',
        ]);

        $perPage = min((int) ($filters['per_page'] ?? 15) ?: 15, 100);
        $page = max(1, (int) ($filters['page'] ?? 1));

        $result = $this->aggregator->listForVendor($vendorCompanyId, $filters + [
            'page' => $page,
            'per_page' => $perPage,
        ]);

        $total = $result['total'];
        $lastPage = max(1, (int) ceil($total / max(1, $perPage)));

        return response()->json([
            'data' => $result['rows'],
            'meta' => [
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $page,
                'last_page' => $lastPage,
            ],
        ]);
    }

    public function show(Request $request, string $documentId): JsonResponse
    {
        $vendorCompanyId = (int) $request->user()->company_id;
        $document = $this->aggregator->findForVendor($documentId, $vendorCompanyId);

        if (! $document) {
            return response()->json(['message' => 'Resource tidak ditemukan.'], 404);
        }

        return response()->json(['data' => $document]);
    }

    public function download(Request $request, string $documentId): Response
    {
        $user = $request->user();
        $vendorCompanyId = (int) $user->company_id;
        $document = $this->aggregator->findForVendor($documentId, $vendorCompanyId);

        if (! $document) {
            return response()->json(['message' => 'Resource tidak ditemukan.'], 404);
        }

        $this->aggregator->logDownload($vendorCompanyId, $documentId, $user->id);

        $source = $document['source'] ?? [];
        $kind = $source['kind'] ?? null;

        if (in_array($kind, ['consignment_note', 'delivery_order', 'job_order_virtual'], true)) {
            $pdf = $this->aggregator->renderPdf($document);
            if ($pdf) {
                return response($pdf->output(), 200, [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'attachment; filename="'.addslashes($document['name'].'.pdf').'"',
                ]);
            }
        }

        if ($kind === 'admin_document') {
            $doc = VendorJobOrderDocument::find((int) ($source['id'] ?? 0));
            if ($doc && Storage::disk('public')->exists($doc->file_path)) {
                $content = Storage::disk('public')->get($doc->file_path);

                return response($content, 200, [
                    'Content-Type' => $doc->mime_type ?: 'application/octet-stream',
                    'Content-Disposition' => 'attachment; filename="'.addslashes($doc->original_name).'"',
                ]);
            }
        }

        if ($kind === 'progress_attachment') {
            $att = VendorProgressAttachment::find((int) ($source['id'] ?? 0));
            if ($att && Storage::disk('public')->exists($att->file_path)) {
                $content = Storage::disk('public')->get($att->file_path);

                return response($content, 200, [
                    'Content-Type' => $att->mime_type ?: 'application/octet-stream',
                    'Content-Disposition' => 'attachment; filename="'.addslashes($att->original_name).'"',
                ]);
            }
        }

        if ($kind === 'pod_signed') {
            $path = $document['file_url'] ? str_replace('/storage/', '', parse_url($document['file_url'], PHP_URL_PATH) ?? '') : null;
            if ($path && Storage::disk('public')->exists($path)) {
                $content = Storage::disk('public')->get($path);

                return response($content, 200, [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'attachment; filename="'.addslashes($document['name'].'.pdf').'"',
                ]);
            }
        }

        return response()->json(['message' => 'File tidak ditemukan.'], 404);
    }
}
