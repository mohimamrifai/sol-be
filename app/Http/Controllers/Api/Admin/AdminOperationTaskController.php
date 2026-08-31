<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Enums\OperationTaskStatus;
use App\Enums\OperationType;
use App\Http\Controllers\Controller;
use App\Models\AdminActivityLog;
use App\Models\OperationTask;
use App\Models\Shipment;
use App\Services\AdminActivityLogger;
use App\Services\OperationTaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AdminOperationTaskController extends Controller
{
    public function __construct(
        private OperationTaskService $operationTaskService,
        private AdminActivityLogger $activityLogger,
    ) {}

    public function stats(Request $request): JsonResponse
    {
        $operationType = $this->resolveOperationType($request);
        $today = now()->toDateString();

        $base = OperationTask::query()
            ->where('operation_type', $operationType)
            ->whereHas('shipment', fn ($q) => $q->whereNotIn('status', Shipment::planningStatuses()));

        return response()->json([
            'data' => [
                'waiting' => (clone $base)->where('status', OperationTaskStatus::Waiting)->count(),
                'in_progress' => (clone $base)->where('status', OperationTaskStatus::InProgress)->count(),
                'completed_today' => (clone $base)->where('status', OperationTaskStatus::Completed)
                    ->whereDate('actual_at', $today)->count(),
                'overdue' => (clone $base)->whereIn('status', [OperationTaskStatus::Waiting, OperationTaskStatus::InProgress])
                    ->whereDate('planned_date', '<', $today)->count(),
                'cancelled' => (clone $base)->where('status', OperationTaskStatus::Cancelled)->count(),
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $operationType = $this->resolveOperationType($request);

        $query = OperationTask::query()
            ->where('operation_type', $operationType)
            ->whereHas('shipment', fn ($q) => $q->whereNotIn('status', Shipment::planningStatuses()))
            ->with([
                'shipment.company:id,name',
                'shipment.booking:id,booking_number',
                'shipment.originLocation:id,code,name',
                'vendorJobOrder.vendor:id,name',
            ]);

        if ($request->filled('search')) {
            $s = $request->search;
            $query->whereHas('shipment', function ($q) use ($s) {
                $q->where('shipment_number', 'like', "%{$s}%")
                    ->orWhereHas('booking', fn ($bq) => $bq->where('booking_number', 'like', "%{$s}%"))
                    ->orWhereHas('company', fn ($cq) => $cq->where('name', 'like', "%{$s}%"));
            });
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('vendor_id')) {
            $query->whereHas('vendorJobOrder', fn ($q) => $q->where('vendor_id', $request->vendor_id));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('planned_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('planned_date', '<=', $request->date_to);
        }
        if ($request->filled('origin_location_id')) {
            $query->whereHas('shipment', fn ($q) => $q->where('origin_location_id', $request->origin_location_id));
        }

        $paginated = $query->orderByDesc('planned_date')->paginate($request->integer('per_page', 15));
        $paginated->getCollection()->transform(fn (OperationTask $task) => $this->transformListRow($task));

        return response()->json($paginated);
    }

    public function show(OperationTask $operationTask): JsonResponse
    {
        $operationTask->load([
            'shipment.company',
            'shipment.booking',
            'shipment.originLocation',
            'shipment.destinationLocation',
            'shipment.serviceType',
            'shipment.containers.containerType',
            'shipment.items',
            'vendorJobOrder.vendor',
        ]);

        return response()->json(['data' => $this->transformDetail($operationTask)]);
    }

    public function start(Request $request, OperationTask $operationTask): JsonResponse
    {
        try {
            $this->operationTaskService->start($operationTask, $request->user()?->id);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Operasi dimulai.', 'data' => $this->transformDetail($operationTask->fresh())]);
    }

    public function complete(Request $request, OperationTask $operationTask): JsonResponse
    {
        try {
            $this->operationTaskService->complete($operationTask, $request->user()?->id);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Operasi selesai.', 'data' => $this->transformDetail($operationTask->fresh())]);
    }

    public function updateRemark(Request $request, OperationTask $operationTask): JsonResponse
    {
        if (! $operationTask->isEditable()) {
            return response()->json(['message' => 'Task tidak dapat diubah.'], 422);
        }

        $data = $request->validate([
            'remark' => 'nullable|string|max:5000',
            'checklist' => 'nullable|array',
        ]);

        $operationTask->update($data);

        $this->activityLogger->log(
            'operation_task',
            'Checklist/remark operasi diperbarui.',
            $operationTask,
            'updated',
            null,
            $request->user()?->id
        );

        return response()->json(['message' => 'Task diperbarui.', 'data' => $this->transformDetail($operationTask->fresh())]);
    }

    public function storeDocument(Request $request, OperationTask $operationTask): JsonResponse
    {
        $data = $request->validate([
            'document_type' => 'nullable|string|max:50',
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        $file = $request->file('file');
        $path = $file->store('operation-tasks/'.$operationTask->id, 'public');
        $documents = $operationTask->metadata['documents'] ?? [];
        $documents[] = [
            'document_type' => $data['document_type'] ?? 'supporting',
            'file_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'uploaded_at' => now()->toIso8601String(),
        ];

        $operationTask->update([
            'metadata' => array_merge($operationTask->metadata ?? [], ['documents' => $documents]),
        ]);

        $this->activityLogger->log(
            'operation_task',
            'Dokumen operasi diunggah: '.$file->getClientOriginalName().'.',
            $operationTask,
            'document_uploaded',
            null,
            $request->user()?->id
        );

        return response()->json(['message' => 'Dokumen diunggah.', 'data' => $this->transformDetail($operationTask->fresh())], 201);
    }

    public function assignVendor(Request $request, OperationTask $operationTask): JsonResponse
    {
        if (! in_array($operationTask->operation_type, [OperationType::Pickup, OperationType::Delivery], true)) {
            return response()->json(['message' => 'Manual assignment hanya untuk pickup/delivery.'], 422);
        }

        $data = $request->validate([
            'vendor_id' => 'required|exists:vendors,id',
        ]);

        try {
            $this->operationTaskService->reassignVendor(
                $operationTask,
                (int) $data['vendor_id'],
                $request->user()?->id
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $operationTask->load([
            'shipment.company',
            'shipment.booking',
            'shipment.originLocation',
            'shipment.destinationLocation',
            'shipment.serviceType',
            'shipment.containers.containerType',
            'shipment.items',
            'vendorJobOrder.vendor',
        ]);

        return response()->json([
            'message' => 'Vendor berhasil di-assign ulang.',
            'data' => $this->transformDetail($operationTask),
        ]);
    }

    private function resolveOperationType(Request $request): OperationType
    {
        $type = $request->route('operationType') ?? $request->input('operation_type');
        abort_unless($type && in_array($type, OperationType::values(), true), 422, 'operation_type wajib diisi.');

        return OperationType::from($type);
    }

    private function defaultChecklist(OperationTask $task): array
    {
        $items = match ($task->operation_type) {
            OperationType::Pickup => [
                ['key' => 'cargo_ready', 'label' => 'Cargo Ready', 'done' => false],
                ['key' => 'document_received', 'label' => 'Document Received', 'done' => false],
                ['key' => 'container_condition', 'label' => 'Container Condition Checked (FCL)', 'done' => false],
                ['key' => 'cargo_loaded', 'label' => 'Cargo Loaded (LCL Pickup)', 'done' => false],
            ],
            default => [
                ['key' => 'verified', 'label' => 'Verified', 'done' => false],
            ],
        };

        return $items;
    }

    private function transformListRow(OperationTask $task): array
    {
        $shipment = $task->shipment;

        return [
            'id' => $task->id,
            'operation_type' => $task->operation_type?->value,
            'operation_label' => $task->operation_type?->label(),
            'shipment_id' => $task->shipment_id,
            'shipment_number' => $shipment?->shipment_number,
            'booking_number' => $shipment?->booking?->booking_number,
            'customer' => $shipment?->company?->name,
            'planned_date' => $task->planned_date?->toDateString(),
            'actual_at' => $task->actual_at?->toIso8601String(),
            'vendor' => $task->vendorJobOrder?->vendor?->name,
            'status' => $task->status?->value,
            'status_label' => $task->status?->label(),
        ];
    }

    private function transformDetail(OperationTask $task): array
    {
        $shipment = $task->shipment;
        $documents = collect($task->metadata['documents'] ?? [])->map(fn ($doc) => [
            ...$doc,
            'url' => isset($doc['file_path']) ? Storage::disk('public')->url($doc['file_path']) : null,
        ]);

        $serviceCode = strtolower((string) ($shipment?->serviceType?->code ?? ''));
        $isFcl = str_contains($serviceCode, 'fcl');
        $items = $shipment?->items?->map(fn ($item) => [
            'description' => $item->description,
            'name' => $item->name,
            'quantity' => $item->quantity,
            'gross_weight' => $item->gross_weight,
            'cbm' => $item->cbm,
        ]) ?? collect();
        $containers = $shipment?->containers?->map(fn ($c) => [
            'container_number' => $c->containerAsset?->container_number ?? $c->container_number,
            'seal_number' => $c->seal_number,
            'container_type' => $c->containerType?->name,
        ]) ?? collect();

        return array_merge($this->transformListRow($task), [
            'remark' => $task->remark,
            'checklist' => $task->checklist ?? $this->defaultChecklist($task),
            'is_editable' => $task->isEditable(),
            'can_start' => $task->status === OperationTaskStatus::Waiting,
            'can_complete' => $task->status === OperationTaskStatus::InProgress,
            'can_reassign_vendor' => $task->isEditable()
                && in_array($task->operation_type, [OperationType::Pickup, OperationType::Delivery], true),
            'shipment' => $shipment ? [
                'id' => $shipment->id,
                'shipment_number' => $shipment->shipment_number,
                'booking_number' => $shipment->booking?->booking_number,
                'customer' => $shipment->company?->name,
                'service_type' => $shipment->serviceType?->name,
                'service_code' => $shipment->serviceType?->code,
                'is_fcl' => $isFcl,
                'shipment_coverage' => $shipment->shipment_coverage,
                'origin' => $shipment->originLocation?->name,
                'destination' => $shipment->destinationLocation?->name,
                'status' => $shipment->status,
                'pickup_address' => $shipment->pickup_remark,
                'pickup_pic' => $shipment->pickup_vendor_pic,
                'pickup_phone' => $shipment->pickup_driver_mobile,
                'planned_pickup_date' => $shipment->pickup_scheduled_at?->toDateString(),
                'delivery_address' => $shipment->delivery_remark,
                'delivery_pic' => $shipment->delivery_vendor_pic,
                'delivery_phone' => $shipment->delivery_driver_mobile,
                'planned_delivery_date' => $shipment->delivery_scheduled_at?->toDateString(),
                'items' => $items,
                'containers' => $containers,
                'total_weight' => $items->sum('gross_weight'),
                'total_volume' => $items->sum('cbm'),
            ] : null,
            'vendor_job_order' => $task->vendorJobOrder ? [
                'id' => $task->vendorJobOrder->id,
                'job_order_number' => $task->vendorJobOrder->job_order_number,
                'vendor' => $task->vendorJobOrder->vendor?->name,
                'driver_name' => $task->vendorJobOrder->driver_name,
                'driver_phone' => $task->vendorJobOrder->driver_mobile,
                'vehicle_plate' => $task->vendorJobOrder->vehicle_plate,
            ] : null,
            'documents' => $documents,
            'activity_log' => AdminActivityLog::query()
                ->with('actor:id,name')
                ->where('module', 'operation_task')
                ->where('subject_type', $task->getMorphClass())
                ->where('subject_id', $task->id)
                ->orderByDesc('occurred_at')
                ->limit(20)
                ->get()
                ->map(fn (AdminActivityLog $log) => [
                    'description' => $log->description,
                    'user' => $log->actor?->name,
                    'occurred_at' => $log->occurred_at?->toIso8601String(),
                ]),
        ]);
    }
}
