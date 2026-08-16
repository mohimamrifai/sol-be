<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\StoreCompanyDocumentRequest;
use App\Http\Resources\Customer\CompanyDocumentResource;
use App\Enums\CompanyDocumentType;
use App\Models\CompanyDocument;
use App\Services\CompanyActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CompanyDocumentController extends Controller
{
    private const DISK = 'local';

    public function __construct(
        private CompanyActivityLogger $activityLogger
    ) {}

    public function index(Request $request): JsonResponse
    {
        $company = $request->user()->company;

        if (! $company) {
            return response()->json(['message' => 'Perusahaan tidak ditemukan.'], 404);
        }

        $documents = $company->documents()->with('uploader:id,name,email')->get();

        return response()->json([
            'data' => CompanyDocumentResource::collection($documents)->resolve(),
        ]);
    }

    public function store(StoreCompanyDocumentRequest $request): JsonResponse
    {
        $user = $request->user();
        $company = $user->company;

        if (! $company) {
            return response()->json(['message' => 'Perusahaan tidak ditemukan.'], 404);
        }

        $data = $request->validated();
        $file = $request->file('file');
        $type = $data['type'] instanceof CompanyDocumentType
            ? $data['type']
            : CompanyDocumentType::from((string) $data['type']);

        $extension = $file->getClientOriginalExtension() ?: 'bin';
        $filename = Str::uuid()->toString().'.'.$extension;
        $stored = $file->storeAs(
            "company-documents/{$company->id}/{$type->value}",
            $filename,
            self::DISK
        );

        if (! $stored) {
            return response()->json(['message' => 'Gagal menyimpan file.'], 500);
        }

        $document = CompanyDocument::create([
            'company_id' => $company->id,
            'type' => $type->value,
            'label' => $data['label'] ?? null,
            'file_path' => $stored,
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'uploaded_by_user_id' => $user->id,
        ]);

        $typeLabel = $type->label();
        $this->activityLogger->log(
            $company,
            'company_document_uploaded',
            'Dokumen '.$typeLabel.' diperbarui.',
            ['document_id' => $document->id, 'type' => $type->value],
            $user->id
        );

        return response()->json([
            'message' => 'Dokumen berhasil diunggah.',
            'data' => (new CompanyDocumentResource($document->load('uploader:id,name,email')))->resolve(),
        ], 201);
    }

    public function show(Request $request, CompanyDocument $document): StreamedResponse|BinaryFileResponse|JsonResponse
    {
        $company = $request->user()->company;

        if (! $company || $document->company_id !== $company->id) {
            return response()->json(['message' => 'Document not found.'], 404);
        }

        return $this->serveFile($document, inline: true);
    }

    public function download(Request $request, CompanyDocument $document): StreamedResponse|BinaryFileResponse|JsonResponse
    {
        $company = $request->user()->company;

        if (! $company || $document->company_id !== $company->id) {
            return response()->json(['message' => 'Document not found.'], 404);
        }

        return $this->serveFile($document, inline: false);
    }

    private function serveFile(CompanyDocument $document, bool $inline): StreamedResponse|BinaryFileResponse
    {
        $disk = Storage::disk(self::DISK);
        if (! $disk->exists($document->file_path)) {
            abort(404, 'File tidak ditemukan di storage.');
        }

        $absolutePath = $disk->path($document->file_path);
        $filename = basename($document->file_path);
        $disposition = $inline ? 'inline' : 'attachment';

        return response()->file($absolutePath, [
            'Content-Type' => $document->mime_type ?? 'application/octet-stream',
            'Content-Disposition' => "{$disposition}; filename=\"{$filename}\"",
        ]);
    }
}
