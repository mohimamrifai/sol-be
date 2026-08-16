<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Vendor;

use App\Enums\VendorJobStatus;
use App\Http\Controllers\Api\Vendor\Concerns\AuthorizesVendorRoles;
use App\Http\Controllers\Controller;
use App\Http\Resources\Vendor\JobOrderResource;
use App\Models\CompanyActivity;
use App\Models\OperationTask;
use App\Models\Shipment;
use App\Models\VendorJobOrderDocument;
use App\Models\VendorProgressAttachment;
use App\Models\VendorProgressUpdate;
use App\Services\VendorJobOrderService;
use App\Support\VendorShipmentHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class JobOrderController extends Controller
{
    use AuthorizesVendorRoles;

    public function __construct(private readonly VendorJobOrderService $vendorJobOrderService) {}

    public function stats(Request $request): JsonResponse
    {
        $vendorCompanyId = $request->user()->company_id;

        return response()->json([
            'data' => [
                'pending_acceptance' => Shipment::forVendor($vendorCompanyId)->where('vendor_status', VendorJobStatus::PendingAcceptance->value)->count(),
                'accepted' => Shipment::forVendor($vendorCompanyId)->where('vendor_status', VendorJobStatus::Accepted->value)->count(),
                'in_progress' => Shipment::forVendor($vendorCompanyId)->where('vendor_status', VendorJobStatus::InProgress->value)->count(),
                'waiting_verification' => Shipment::forVendor($vendorCompanyId)->where('vendor_status', VendorJobStatus::WaitingVerification->value)->count(),
                'completed' => Shipment::forVendor($vendorCompanyId)->where('vendor_status', VendorJobStatus::Completed->value)->count(),
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $vendorCompanyId = $request->user()->company_id;
        $query = Shipment::forVendor($vendorCompanyId)
            ->with(['company:id,name,company_code', 'serviceType:id,code,name', 'originLocation:id,code,name', 'destinationLocation:id,code,name']);

        if ($search = $request->string('search')->toString()) {
            $query->where(function ($q) use ($search) {
                $q->where('shipment_number', 'like', "%{$search}%")
                    ->orWhere('shipment_no', 'like', "%{$search}%")
                    ->orWhereHas('company', fn ($c) => $c->where('name', 'like', "%{$search}%"));
            });
        }

        if (($status = $request->string('status')->toString()) && $status !== 'all') {
            $query->where('vendor_status', $status);
        }

        if ($serviceTypeId = $request->integer('service_type_id')) {
            $query->where('service_type_id', $serviceTypeId);
        }

        $assignedFrom = $request->date('assigned_from') ?? $request->date('from');
        $assignedTo = $request->date('assigned_to') ?? $request->date('to');
        if ($assignedFrom) {
            $query->whereDate('created_at', '>=', $assignedFrom);
        }
        if ($assignedTo) {
            $query->whereDate('created_at', '<=', $assignedTo);
        }

        $sort = $request->string('sort')->toString() ?: 'created_at';
        $direction = $request->string('direction')->toString() ?: 'desc';
        $query->orderBy($sort, $direction);

        $perPage = min((int) $request->integer('per_page', 15) ?: 15, 100);
        $page = $query->paginate($perPage);

        return response()->json([
            'data' => JobOrderResource::collection($page->getCollection())->resolve(),
            'meta' => [
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, Shipment $shipment): JsonResponse
    {
        $this->authorizeVendorAccess($request, $shipment);

        $shipment->load([
            'company:id,name,company_code,email,phone',
            'serviceType:id,code,name',
            'transportMode:id,code,name',
            'originLocation:id,code,name,city,province',
            'destinationLocation:id,code,name,city,province',
            'originYard:id,code,name',
            'destinationYard:id,code,name',
            'internalPic:id,name',
            'items.container',
            'containers',
            'adminVendorJobOrders.documents.uploadedBy:id,name',
            'operationTasks',
            'progressUpdates' => function ($q) {
                $q->with(['submittedByUser:id,name', 'attachments'])->orderByDesc('submitted_at');
            },
        ]);

        $activities = CompanyActivity::query()
            ->where('subject_type', Shipment::class)
            ->where('subject_id', $shipment->id)
            ->with('actor:id,name')
            ->orderByDesc('occurred_at')
            ->get()
            ->map(fn (CompanyActivity $a) => [
                'id' => $a->id,
                'event_key' => $a->event_key,
                'description' => $a->description,
                'actor_name' => $a->actor?->name,
                'occurred_at' => $a->occurred_at?->toIso8601String(),
            ]);

        $vendorJo = $shipment->adminVendorJobOrders->first();

        $internalDocs = $shipment->adminVendorJobOrders
            ->flatMap(fn ($vjo) => $vjo->documents)
            ->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->original_name,
                'document_type' => $a->document_type,
                'mime_type' => $a->mime_type,
                'size' => (int) $a->size,
                'file_url' => Storage::url($a->file_path),
                'uploaded_by' => $a->uploadedBy?->name ?? 'Internal',
                'uploaded_at' => $a->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $supportingDocs = VendorProgressAttachment::query()
            ->whereHas('progressUpdate', fn ($q) => $q->where('shipment_id', $shipment->id))
            ->with('progressUpdate.submittedByUser:id,name')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->original_name,
                'mime_type' => $a->mime_type,
                'size' => (int) $a->size,
                'file_url' => Storage::url($a->file_path),
                'uploaded_by' => $a->progressUpdate?->submittedByUser?->name,
                'uploaded_at' => $a->created_at?->toIso8601String(),
            ]);

        // Build timeline from operation tasks + key dates
        $timeline = $shipment->operationTasks
            ->sortBy('actual_at')
            ->map(fn (OperationTask $t) => [
                'event' => $t->operation_type?->value ?? 'operation',
                'activity' => $t->operation_type?->label() ?? 'Operation',
                'description' => $t->operation_type?->label() ?? 'Operation update',
                'status' => $t->status?->value ?? 'pending',
                'status_label' => $t->status?->label() ?? 'Pending',
                'updated_by' => 'Internal',
                'occurred_at' => ($t->actual_at ?? $t->planned_date)?->toIso8601String(),
            ])
            ->values()
            ->all();

        if (empty($timeline)) {
            $timeline = collect([
                [
                    'event' => 'assigned',
                    'activity' => 'Job Order Assigned',
                    'description' => 'Job order ditugaskan ke vendor.',
                    'status' => 'completed',
                    'status_label' => 'Completed',
                    'updated_by' => 'Internal',
                    'occurred_at' => $shipment->created_at?->toIso8601String(),
                ],
                $shipment->accepted_at ? [
                    'event' => 'accepted',
                    'activity' => 'Job Accepted',
                    'description' => 'Job order diterima vendor.',
                    'status' => 'completed',
                    'status_label' => 'Completed',
                    'updated_by' => 'Vendor',
                    'occurred_at' => $shipment->accepted_at->toIso8601String(),
                ] : null,
                $shipment->completion_submitted_at ? [
                    'event' => 'completion_submitted',
                    'activity' => 'Completion Submitted',
                    'description' => 'Vendor mengajukan penyelesaian job order.',
                    'status' => 'completed',
                    'status_label' => 'Completed',
                    'updated_by' => 'Vendor',
                    'occurred_at' => $shipment->completion_submitted_at->toIso8601String(),
                ] : null,
                $shipment->completion_verified_at ? [
                    'event' => 'completion_verified',
                    'activity' => 'Completion Verified',
                    'description' => 'Internal memverifikasi penyelesaian job order.',
                    'status' => 'completed',
                    'status_label' => 'Completed',
                    'updated_by' => 'Internal',
                    'occurred_at' => $shipment->completion_verified_at->toIso8601String(),
                ] : null,
            ])->filter()->values()->all();
        }

        $jobDetails = [
            'containers' => $shipment->containers->map(fn ($c) => [
                'container_no' => $c->container_number ?? $c->number ?? null,
                'container_type' => $c->container_type ?? $c->type ?? null,
            ])->all(),
            'cargo_description' => $shipment->items->pluck('description')->filter()->implode('; ') ?: $shipment->items->pluck('name')->filter()->implode('; '),
            'pickup_location' => $vendorJo?->pickup_address ?? $shipment->originLocation?->name,
            'delivery_location' => $vendorJo?->delivery_address ?? $shipment->destinationLocation?->name,
            'special_instruction' => $vendorJo?->pickup_remark ?? $vendorJo?->delivery_remark ?? $shipment->planning_notes ?? $shipment->notes,
            'vehicle_type' => $vendorJo?->vehicle_type,
            'vehicle_plate' => $vendorJo?->vehicle_plate,
            'driver_name' => $vendorJo?->driver_name,
        ];

        return response()->json([
            'data' => array_merge(
                (new JobOrderResource($shipment))->resolve($request),
                [
                    'priority' => 'Normal',
                    'assigned_by' => $shipment->internalPic?->name,
                    'job_description' => $shipment->planning_notes ?? $shipment->notes,
                    'work_location' => $shipment->originYard?->name ?? $shipment->destinationYard?->name ?? $shipment->originLocation?->name,
                    'job_details' => $jobDetails,
                    'internal_documents' => $internalDocs,
                    'progress_updates' => $shipment->progressUpdates->map(fn ($u) => [
                        'id' => $u->id,
                        'progress_notes' => $u->progress_notes,
                        'completion_remark' => $u->completion_remark,
                        'submitted_by' => $u->submittedByUser?->name,
                        'submitted_at' => $u->submitted_at?->toIso8601String(),
                        'attachments' => $u->attachments->map(fn ($a) => [
                            'id' => $a->id,
                            'file_path' => $a->file_path,
                            'file_url' => Storage::url($a->file_path),
                            'original_name' => $a->original_name,
                            'mime_type' => $a->mime_type,
                            'size' => (int) $a->size,
                        ]),
                    ]),
                    'supporting_documents' => $supportingDocs,
                    'timeline' => $timeline,
                    'activities' => $activities,
                ]
            ),
        ]);
    }

    public function accept(Request $request, Shipment $shipment): JsonResponse
    {
        $this->authorizeJobOrderWrite($request);
        $this->authorizeVendorAccess($request, $shipment);

        if ($shipment->vendor_status !== VendorJobStatus::PendingAcceptance->value) {
            return response()->json(['message' => 'Job order tidak dalam status Pending Acceptance.'], 422);
        }

        $shipment = DB::transaction(function () use ($shipment, $request) {
            $shipment->update([
                'vendor_status' => VendorJobStatus::Accepted->value,
                'accepted_at' => now(),
                'status' => 'cargo_received',
            ]);

            CompanyActivity::create([
                'subject_type' => Shipment::class,
                'subject_id' => $shipment->id,
                'event_key' => 'vendor_job_accepted',
                'description' => 'Job order diterima oleh vendor.',
                'actor_user_id' => $request->user()->id,
                'occurred_at' => now(),
            ]);

            return $shipment->fresh();
        });

        $this->vendorJobOrderService->syncStatusFromShipment($shipment, $request->user()?->id);

        return response()->json([
            'message' => 'Job order berhasil diterima.',
            'data' => (new JobOrderResource($shipment))->resolve($request),
        ]);
    }

    public function reject(Request $request, Shipment $shipment): JsonResponse
    {
        $this->authorizeJobOrderWrite($request);
        $this->authorizeVendorAccess($request, $shipment);

        if ($shipment->vendor_status !== VendorJobStatus::PendingAcceptance->value) {
            return response()->json(['message' => 'Job order tidak dalam status Pending Acceptance.'], 422);
        }

        $request->validate([
            'rejection_reason' => 'nullable|string|max:2000',
        ]);

        $shipment = DB::transaction(function () use ($shipment, $request) {
            $shipment->update([
                'vendor_status' => VendorJobStatus::Rejected->value,
                'vendor_rejection_reason' => $request->input('rejection_reason'),
            ]);

            CompanyActivity::create([
                'subject_type' => Shipment::class,
                'subject_id' => $shipment->id,
                'event_key' => 'vendor_job_rejected',
                'description' => 'Job order ditolak oleh vendor.',
                'meta' => ['reason' => $request->input('rejection_reason')],
                'actor_user_id' => $request->user()->id,
                'occurred_at' => now(),
            ]);

            return $shipment->fresh();
        });

        $this->vendorJobOrderService->syncStatusFromShipment($shipment, $request->user()?->id);

        return response()->json([
            'message' => 'Job order berhasil ditolak.',
            'data' => (new JobOrderResource($shipment))->resolve($request),
        ]);
    }

    public function submitProgress(Request $request, Shipment $shipment): JsonResponse
    {
        $this->authorizeJobOrderWrite($request);
        $this->authorizeVendorAccess($request, $shipment);

        if (! in_array($shipment->vendor_status, [
            VendorJobStatus::Accepted->value,
            VendorJobStatus::InProgress->value,
        ], true)) {
            return response()->json(['message' => 'Job order belum dapat menerima progress update.'], 422);
        }

        $request->validate([
            'progress_notes' => 'nullable|string|max:2000',
            'attachments' => 'nullable|array',
            'attachments.*' => 'file|mimes:jpg,jpeg,png,pdf|max:10240',
        ]);

        if (! $request->input('progress_notes') && ! $request->hasFile('attachments')) {
            return response()->json(['message' => 'Progress notes atau evidence wajib diisi.'], 422);
        }

        $update = DB::transaction(function () use ($request, $shipment) {
            $update = VendorProgressUpdate::create([
                'shipment_id' => $shipment->id,
                'progress_notes' => $request->input('progress_notes'),
                'completion_remark' => null,
                'submitted_by' => $request->user()->id,
                'submitted_at' => now(),
            ]);

            if ($request->hasFile('attachments')) {
                foreach ($request->file('attachments') as $file) {
                    $path = $file->store("vendor-progress/{$shipment->id}/{$update->id}", 'public');
                    $update->attachments()->create([
                        'file_path' => $path,
                        'original_name' => $file->getClientOriginalName(),
                        'mime_type' => $file->getMimeType(),
                        'size' => $file->getSize(),
                    ]);
                }
            }

            // Transition to InProgress if still Accepted
            if ($shipment->vendor_status === VendorJobStatus::Accepted->value) {
                $shipment->update(['vendor_status' => VendorJobStatus::InProgress->value]);
            }

            CompanyActivity::create([
                'subject_type' => Shipment::class,
                'subject_id' => $shipment->id,
                'event_key' => 'vendor_progress_submitted',
                'description' => 'Progress update dikirim oleh vendor.',
                'meta' => ['update_id' => $update->id],
                'actor_user_id' => $request->user()->id,
                'occurred_at' => now(),
            ]);

            return $update->load('attachments');
        });

        $this->vendorJobOrderService->syncStatusFromShipment($shipment->fresh(), $request->user()?->id);

        return response()->json([
            'message' => 'Progress berhasil dikirim.',
            'data' => $update,
        ]);
    }

    public function submitCompletion(Request $request, Shipment $shipment): JsonResponse
    {
        $this->authorizeJobOrderWrite($request);
        $this->authorizeVendorAccess($request, $shipment);

        if (! in_array($shipment->vendor_status, [
            VendorJobStatus::InProgress->value,
        ], true)) {
            return response()->json(['message' => 'Job order tidak dapat diselesaikan saat ini.'], 422);
        }

        $request->validate([
            'completion_remark' => 'required|string|max:2000',
            'final_attachments' => 'nullable|array',
            'final_attachments.*' => 'file|mimes:jpg,jpeg,png,pdf|max:10240',
        ]);

        $shipment = DB::transaction(function () use ($request, $shipment) {
            $update = VendorProgressUpdate::create([
                'shipment_id' => $shipment->id,
                'progress_notes' => 'Pekerjaan selesai.',
                'completion_remark' => $request->input('completion_remark'),
                'submitted_by' => $request->user()->id,
                'submitted_at' => now(),
            ]);

            if ($request->hasFile('final_attachments')) {
                foreach ($request->file('final_attachments') as $file) {
                    $path = $file->store("vendor-progress/{$shipment->id}/{$update->id}", 'public');
                    $update->attachments()->create([
                        'file_path' => $path,
                        'original_name' => $file->getClientOriginalName(),
                        'mime_type' => $file->getMimeType(),
                        'size' => $file->getSize(),
                    ]);
                }
            }

            $shipment->update([
                'vendor_status' => VendorJobStatus::WaitingVerification->value,
                'completion_submitted_at' => now(),
                'completion_remark' => $request->input('completion_remark'),
            ]);

            CompanyActivity::create([
                'subject_type' => Shipment::class,
                'subject_id' => $shipment->id,
                'event_key' => 'vendor_completion_submitted',
                'description' => 'Vendor mengajukan penyelesaian job order.',
                'meta' => ['remark_preview' => substr($request->input('completion_remark'), 0, 60)],
                'actor_user_id' => $request->user()->id,
                'occurred_at' => now(),
            ]);

            return $shipment->fresh();
        });

        $this->vendorJobOrderService->syncStatusFromShipment($shipment, $request->user()?->id);

        return response()->json([
            'message' => 'Penyelesaian job order berhasil diajukan.',
            'data' => (new JobOrderResource($shipment))->resolve($request),
        ]);
    }

    public function activities(Request $request, Shipment $shipment): JsonResponse
    {
        $this->authorizeVendorAccess($request, $shipment);

        $activities = CompanyActivity::where('subject_type', Shipment::class)
            ->where('subject_id', $shipment->id)
            ->with('actor:id,name')
            ->orderByDesc('occurred_at')
            ->limit(50)
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'event_key' => $a->event_key,
                'description' => $a->description,
                'meta' => $a->meta,
                'actor_name' => $a->actor?->name,
                'occurred_at' => $a->occurred_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $activities]);
    }

    private function authorizeVendorAccess(Request $request, Shipment $shipment): void
    {
        if ($shipment->vendor_company_id !== $request->user()->company_id) {
            abort(response()->json(['message' => 'Resource tidak ditemukan.'], 404));
        }
    }
}
