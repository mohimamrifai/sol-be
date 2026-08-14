<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Enums\PodStatus;
use App\Http\Controllers\Controller;
use App\Models\AdminActivityLog;
use App\Models\ProofOfDelivery;
use App\Services\ProofOfDeliveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AdminProofOfDeliveryController extends Controller
{
    public function __construct(private ProofOfDeliveryService $podService) {}

    public function stats(): JsonResponse
    {
        $base = ProofOfDelivery::query();

        return response()->json([
            'data' => [
                'waiting_pod' => (clone $base)->where('status', PodStatus::WaitingPod)->count(),
                'received' => (clone $base)->where('status', PodStatus::Received)->count(),
                'verified' => (clone $base)->where('status', PodStatus::Verified)->count(),
                'rejected' => (clone $base)->where('status', PodStatus::Rejected)->count(),
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = ProofOfDelivery::query()
            ->with([
                'shipment.company:id,name',
                'shipment.serviceType:id,name,code',
            ]);

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('pod_number', 'like', "%{$s}%")
                    ->orWhereHas('shipment', fn ($sq) => $sq
                        ->where('shipment_number', 'like', "%{$s}%")
                        ->orWhereHas('company', fn ($cq) => $cq->where('name', 'like', "%{$s}%")));
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('company_id')) {
            $query->whereHas('shipment', fn ($q) => $q->where('company_id', $request->company_id));
        }

        if ($request->filled('pod_date_from')) {
            $query->whereDate('pod_date', '>=', $request->pod_date_from);
        }

        if ($request->filled('pod_date_to')) {
            $query->whereDate('pod_date', '<=', $request->pod_date_to);
        }

        $paginated = $query->orderByDesc('updated_at')->paginate($request->integer('per_page', 15));
        $paginated->getCollection()->transform(fn (ProofOfDelivery $pod) => $this->transformListRow($pod));

        return response()->json($paginated);
    }

    public function show(ProofOfDelivery $proofOfDelivery): JsonResponse
    {
        $proofOfDelivery->load([
            'shipment.company',
            'shipment.booking',
            'shipment.originLocation',
            'shipment.destinationLocation',
            'shipment.serviceType',
            'verifier:id,name',
            'submitter:id,name',
        ]);

        return response()->json(['data' => $this->transformDetail($proofOfDelivery)]);
    }

    public function submit(Request $request, ProofOfDelivery $proofOfDelivery): JsonResponse
    {
        $data = $request->validate([
            'receiver_name' => 'required|string|max:255',
            'receiver_position' => 'nullable|string|max:255',
            'received_at' => 'required|date',
            'remark' => 'nullable|string|max:5000',
            'signed_pod' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
            'delivery_photo' => 'nullable|file|mimes:jpg,jpeg,png|max:10240',
            'other_documents' => 'nullable|array',
            'other_documents.*' => 'file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        $data['signed_pod'] = $request->file('signed_pod');
        $data['delivery_photo'] = $request->file('delivery_photo');
        $data['other_documents'] = $request->file('other_documents') ?? [];

        try {
            $pod = $this->podService->submit($proofOfDelivery, $data, $request->user()?->id);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'POD berhasil disubmit.',
            'data' => $this->transformDetail($pod),
        ]);
    }

    public function verify(Request $request, ProofOfDelivery $proofOfDelivery): JsonResponse
    {
        $data = $request->validate([
            'verification_notes' => 'nullable|string|max:5000',
        ]);

        try {
            $pod = $this->podService->verify($proofOfDelivery, $data['verification_notes'] ?? null, $request->user()?->id);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'POD berhasil diverifikasi.',
            'data' => $this->transformDetail($pod),
        ]);
    }

    public function reject(Request $request, ProofOfDelivery $proofOfDelivery): JsonResponse
    {
        $data = $request->validate([
            'verification_notes' => 'nullable|string|max:5000',
        ]);

        try {
            $pod = $this->podService->reject($proofOfDelivery, $data['verification_notes'] ?? null, $request->user()?->id);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'POD ditolak.',
            'data' => $this->transformDetail($pod),
        ]);
    }

    private function transformListRow(ProofOfDelivery $pod): array
    {
        $shipment = $pod->shipment;

        return [
            'id' => $pod->id,
            'pod_number' => $pod->pod_number,
            'shipment_id' => $pod->shipment_id,
            'shipment_number' => $shipment?->shipment_number,
            'customer' => $shipment?->company?->name,
            'delivery_date' => $shipment?->delivery_scheduled_at?->toIso8601String(),
            'pod_date' => $pod->pod_date?->toIso8601String(),
            'status' => $pod->status?->value,
            'status_label' => $pod->status?->label(),
        ];
    }

    private function transformDetail(ProofOfDelivery $pod): array
    {
        $shipment = $pod->shipment;
        $otherDocs = collect($pod->other_documents ?? [])->map(fn ($doc) => [
            ...$doc,
            'url' => isset($doc['file_path']) ? Storage::disk('public')->url($doc['file_path']) : null,
        ]);

        return array_merge($this->transformListRow($pod), [
            'receiver_name' => $pod->receiver_name,
            'receiver_position' => $pod->receiver_position,
            'received_at' => $pod->received_at?->toIso8601String(),
            'remark' => $pod->remark,
            'signed_pod_url' => $pod->signed_pod_path ? Storage::disk('public')->url($pod->signed_pod_path) : null,
            'delivery_photo_url' => $pod->delivery_photo_path ? Storage::disk('public')->url($pod->delivery_photo_path) : null,
            'other_documents' => $otherDocs,
            'verification_status' => $pod->status?->value,
            'verification_status_label' => match ($pod->status) {
                PodStatus::WaitingPod => 'Waiting',
                PodStatus::Received => 'Waiting',
                PodStatus::Verified => 'Verified',
                PodStatus::Rejected => 'Rejected',
            },
            'verified_by' => $pod->verifier?->name,
            'verified_at' => $pod->verified_at?->toIso8601String(),
            'verification_notes' => $pod->verification_notes,
            'submitted_by' => $pod->submitter?->name,
            'can_submit' => $pod->canSubmit(),
            'can_verify' => $pod->canVerify(),
            'can_reject' => $pod->canVerify(),
            'is_read_only' => $pod->isReadOnly(),
            'shipment' => $shipment ? [
                'shipment_number' => $shipment->shipment_number,
                'customer' => $shipment->company?->name,
                'service_type' => $shipment->serviceType?->name,
                'shipment_coverage' => $shipment->shipment_coverage,
                'origin' => $shipment->originLocation?->name,
                'destination' => $shipment->destinationLocation?->name,
                'delivery_date' => $shipment->delivery_scheduled_at?->toIso8601String(),
                'status' => $shipment->status,
            ] : null,
            'activity_log' => AdminActivityLog::query()
                ->with('actor:id,name')
                ->where('module', 'proof_of_delivery')
                ->where('subject_type', $pod->getMorphClass())
                ->where('subject_id', $pod->id)
                ->orderByDesc('occurred_at')
                ->limit(30)
                ->get()
                ->map(fn (AdminActivityLog $log) => [
                    'description' => $log->description,
                    'user' => $log->actor?->name,
                    'occurred_at' => $log->occurred_at?->toIso8601String(),
                ]),
        ]);
    }
}
