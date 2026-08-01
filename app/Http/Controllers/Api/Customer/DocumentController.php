<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Shipment;
use App\Services\DocumentAggregatorService;
use App\Services\DocumentPdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class DocumentController extends Controller
{
    public function __construct(
        private DocumentAggregatorService $aggregator,
        private DocumentPdfService $pdf,
    ) {}

    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();
        $stats = $this->aggregator->statsForCompany((int) $user->company_id);

        return response()->json(['data' => $stats]);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:200'],
            'type' => ['nullable', 'string', 'in:booking,shipment,billing'],
            'shipment_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = (int) ($data['per_page'] ?? 15);
        $page = (int) ($data['page'] ?? 1);

        $result = $this->aggregator->listForCompany((int) $user->company_id, $data + [
            'page' => $page,
            'per_page' => $perPage,
        ]);

        $total = $result['total'];
        $lastPage = max(1, (int) ceil($total / max(1, $perPage)));

        return response()->json([
            'data' => $result['rows'],
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => $lastPage,
            'from' => $total > 0 ? (($page - 1) * $perPage) + 1 : null,
            'to' => $total > 0 ? min($page * $perPage, $total) : null,
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $document = $this->aggregator->findForCompany($id, (int) $user->company_id);

        if (! $document) {
            return response()->json(['message' => 'Dokumen tidak ditemukan.'], 404);
        }

        return response()->json(['data' => $document]);
    }

    public function download(Request $request, string $id): SymfonyResponse
    {
        $user = $request->user();
        $document = $this->aggregator->findForCompany($id, (int) $user->company_id);

        if (! $document) {
            abort(404, 'Dokumen tidak ditemukan.');
        }

        return $this->buildFileResponse($document, forceDownload: true);
    }

    public function preview(Request $request, string $id): SymfonyResponse
    {
        $user = $request->user();
        $document = $this->aggregator->findForCompany($id, (int) $user->company_id);

        if (! $document) {
            abort(404, 'Dokumen tidak ditemukan.');
        }

        return $this->buildFileResponse($document, forceDownload: false);
    }

    public function shipmentOptions(Request $request): JsonResponse
    {
        $user = $request->user();
        return response()->json(['data' => $this->aggregator->shipmentOptions((int) $user->company_id)]);
    }

    private function buildFileResponse(array $document, bool $forceDownload): SymfonyResponse
    {
        $source = $document['source'] ?? [];
        $kind = $source['kind'] ?? null;

        return match ($kind) {
            'booking_attachment' => $this->serveBookingAttachment($source, $document, $forceDownload),
            'consignment_note' => $this->servePdf(
                $this->pdf->renderConsignmentNote(Shipment::with([])->findOrFail((int) $source['id'])),
                $this->safeFilename($document['name'] ?? 'consignment-note', 'pdf'),
                $forceDownload,
            ),
            'delivery_order' => $this->servePdf(
                $this->pdf->renderDeliveryOrder(Shipment::with([])->findOrFail((int) $source['id'])),
                $this->safeFilename($document['name'] ?? 'delivery-order', 'pdf'),
                $forceDownload,
            ),
            'proof_of_delivery' => $this->servePhoto($source, $document, $forceDownload),
            'invoice' => $this->servePdf(
                $this->pdf->renderInvoice(Invoice::with([])->findOrFail((int) $source['id'])),
                $this->safeFilename($document['name'] ?? 'invoice', 'pdf'),
                $forceDownload,
            ),
            'tax_invoice' => $this->servePdf(
                $this->pdf->renderInvoice(Invoice::with([])->findOrFail((int) $source['id']), tax: true),
                $this->safeFilename($document['name'] ?? 'tax-invoice', 'pdf'),
                $forceDownload,
            ),
            'payment_receipt' => $this->servePdf(
                $this->pdf->renderPaymentReceipt(Payment::with([])->findOrFail((int) $source['id'])),
                $this->safeFilename($document['name'] ?? 'payment-receipt', 'pdf'),
                $forceDownload,
            ),
            default => abort(404, 'Jenis dokumen tidak dikenali.'),
        };
    }

    private function serveBookingAttachment(array $source, array $document, bool $force): SymfonyResponse
    {
        $path = $source['file_path'] ?? null;
        if (! $path || ! Storage::disk('public')->exists($path)) {
            abort(404, 'File tidak ditemukan.');
        }

        $disk = Storage::disk('public');
        $mime = $document['mime_type'] ?? $this->guessMime($path);
        $filename = $document['name'] ?? basename($path);
        $content = $disk->get($path);

        $headers = [
            'Content-Type' => $mime,
            'Content-Length' => (string) strlen($content),
        ];
        if ($force) {
            $headers['Content-Disposition'] = 'attachment; filename="' . addslashes($filename) . '"';
        } else {
            $headers['Content-Disposition'] = 'inline; filename="' . addslashes($filename) . '"';
        }

        return response($content, 200, $headers);
    }

    private function servePhoto(array $source, array $document, bool $force): SymfonyResponse
    {
        $path = $source['file_path'] ?? null;
        if (! $path || ! Storage::disk('public')->exists($path)) {
            abort(404, 'File tidak ditemukan.');
        }

        $disk = Storage::disk('public');
        $mime = $document['mime_type'] ?? $this->guessMime($path);
        $content = $disk->get($path);
        $filename = $document['name'] ?? basename($path);

        $headers = [
            'Content-Type' => $mime,
            'Content-Length' => (string) strlen($content),
        ];
        $headers['Content-Disposition'] = ($force ? 'attachment' : 'inline')
            . '; filename="' . addslashes($filename) . '"';

        return response($content, 200, $headers);
    }

    private function guessMime(string $path): string
    {
        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        return match ($ext) {
            'pdf' => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            default => 'application/octet-stream',
        };
    }

    private function servePdf($pdf, string $filename, bool $force): SymfonyResponse
    {
        $content = $pdf->output();
        $headers = [
            'Content-Type' => 'application/pdf',
            'Content-Length' => (string) strlen($content),
        ];
        $headers['Content-Disposition'] = ($force ? 'attachment' : 'inline')
            . '; filename="' . addslashes($filename) . '"';

        return response($content, 200, $headers);
    }

    private function safeFilename(string $name, string $fallbackExt): string
    {
        $clean = preg_replace('/[^\w\-\.]+/u', '_', $name) ?? $fallbackExt;
        $clean = trim($clean, '_');
        if ($clean === '') $clean = 'document';
        if (! str_contains($clean, '.')) $clean .= '.' . $fallbackExt;
        return $clean;
    }
}
