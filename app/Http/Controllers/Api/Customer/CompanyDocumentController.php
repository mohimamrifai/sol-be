<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\StoreCompanyDocumentRequest;
use App\Http\Resources\Customer\CompanyDocumentResource;
use App\Models\Company;
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

    public function index(Request $request, Company $company): JsonResponse
    {
        $user = $request->user();
        if ($company->id !== $user->company_id) {
            return response()->json(['message' => 'Company not found.'], 404);
        }

        $documents = $company->documents()->with('uploader:id,name,email')->get();

        return response()->json([
            'data' => CompanyDocumentResource::collection($documents)->resolve(),
        ]);
    }

    public function store(StoreCompanyDocumentRequest $request, Company $company): JsonResponse
    {
        $user = $request->user();
        if ($company->id !== $user->company_id) {
            return response()->json(['message' => 'Company not found.'], 404);
        }

        $data = $request->validated();
        $file = $request->file('file');

        $extension = $file->getClientOriginalExtension() ?: 'bin';
        $filename = Str::uuid()->toString().'.'.$extension;
        $stored = $file->storeAs(
            "company-documents/{$company->id}/{$data['type']->value}",
            $filename,
            self::DISK
        );

        if (! $stored) {
            return response()->json(['message' => 'Gagal menyimpan file.'], 500);
        }

        $document = CompanyDocument::create([
            'company_id' => $company->id,
            'type' => $data['type']->value,
            'label' => $data['label'] ?? null,
            'file_path' => $stored,
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'uploaded_by_user_id' => $user->id,
        ]);

        $this->activityLogger->log(
            $company,
            'company_document_uploaded',
            'Dokumen '.strtoupper($data['type']->value).' diperbarui.',
            ['document_id' => $document->id, 'type' => $data['type']->value],
            $user->id
        );

        return response()->json([
            'message' => 'Dokumen berhasil diunggah.',
            'data' => (new CompanyDocumentResource($document->load('uploader:id,name,email')))->resolve(),
        ], 201);
    }

    public function show(Request $request, Company $company, CompanyDocument $document): StreamedResponse|BinaryFileResponse|JsonResponse
    {
        $user = $request->user();
        if ($company->id !== $user->company_id || $document->company_id !== $company->id) {
            return response()->json(['message' => 'Document not found.'], 404);
        }

        return $this->serveFile($document, inline: true);
    }

    public function download(Request $request, Company $company, CompanyDocument $document): StreamedResponse|BinaryFileResponse|JsonResponse
    {
        $user = $request->user();
        if ($company->id !== $user->company_id || $document->company_id !== $company->id) {
            return response()->json(['message' => 'Document not found.'], 404);
        }

        return $this->serveFile($document, inline: false);
    }

    public function destroy(Request $request, Company $company, CompanyDocument $document): JsonResponse
    {
        $user = $request->user();
        if ($company->id !== $user->company_id || $document->company_id !== $company->id) {
            return response()->json(['message' => 'Document not found.'], 404);
        }

        if (Storage::disk(self::DISK)->exists($document->file_path)) {
            Storage::disk(self::DISK)->delete($document->file_path);
        }

        $type = $document->type->value;
        $document->delete();

        $this->activityLogger->log(
            $company,
            'company_document_deleted',
            'Dokumen '.strtoupper($type).' dihapus.',
            ['type' => $type],
            $user->id
        );

        return response()->json(['message' => 'Dokumen berhasil dihapus.']);
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
