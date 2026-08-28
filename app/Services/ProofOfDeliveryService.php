<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PodStatus;
use App\Models\ProofOfDelivery;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ProofOfDeliveryService
{
    public function __construct(
        private AdminActivityLogger $activityLogger,
        private ShipmentActivityLogger $shipmentActivityLogger,
    ) {}

    public function ensureForShipment(Shipment $shipment, ?int $actorUserId = null): ProofOfDelivery
    {
        $pod = ProofOfDelivery::query()->firstOrCreate(
            ['shipment_id' => $shipment->id],
            ['status' => PodStatus::WaitingPod]
        );

        if ($pod->wasRecentlyCreated) {
            $this->activityLogger->log(
                'proof_of_delivery',
                'Delivery Completed.',
                $pod,
                'created',
                null,
                $actorUserId
            );
        }

        if ($shipment->status !== 'completed') {
            $shipment->update(['status' => 'proof_of_delivery']);
        }

        return $pod;
    }

    /**
     * @param array{
     *   receiver_name: string,
     *   receiver_position?: string|null,
     *   received_at: string,
     *   remark?: string|null,
     *   signed_pod: UploadedFile,
     *   delivery_photo?: UploadedFile|null,
     *   other_documents?: array<int, UploadedFile>|null
     * } $data
     */
    public function submit(ProofOfDelivery $pod, array $data, ?int $actorUserId = null): ProofOfDelivery
    {
        if (! $pod->canSubmit()) {
            throw new \RuntimeException('POD tidak dapat disubmit pada status saat ini.');
        }

        if (! isset($data['signed_pod'])) {
            throw new \RuntimeException('Signed POD wajib diunggah.');
        }

        $signedPath = $data['signed_pod']->store('proof-of-delivery/'.$pod->id, 'public');
        $deliveryPhotoPath = isset($data['delivery_photo'])
            ? $data['delivery_photo']->store('proof-of-delivery/'.$pod->id, 'public')
            : $pod->delivery_photo_path;

        $otherDocs = $pod->other_documents ?? [];
        foreach ($data['other_documents'] ?? [] as $file) {
            if ($file instanceof UploadedFile) {
                $otherDocs[] = [
                    'file_path' => $file->store('proof-of-delivery/'.$pod->id, 'public'),
                    'original_name' => $file->getClientOriginalName(),
                    'uploaded_at' => now()->toIso8601String(),
                ];
            }
        }

        $now = now();
        $pod->update([
            'status' => PodStatus::Received,
            'receiver_name' => $data['receiver_name'],
            'receiver_position' => $data['receiver_position'] ?? null,
            'received_at' => $data['received_at'],
            'remark' => $data['remark'] ?? null,
            'pod_date' => $now,
            'signed_pod_path' => $signedPath,
            'delivery_photo_path' => $deliveryPhotoPath,
            'other_documents' => $otherDocs,
            'submitted_by' => $actorUserId,
            'verification_notes' => null,
            'verified_by' => null,
            'verified_at' => null,
        ]);

        $shipment = $pod->shipment;
        if ($shipment) {
            $shipment->trackings()->create([
                'status' => 'pod_uploaded',
                'notes' => 'POD Uploaded',
                'tracked_at' => $now,
                'updated_by' => $actorUserId,
            ]);
            $this->shipmentActivityLogger->log(
                $shipment,
                'pod_uploaded',
                'POD diunggah.',
                $actorUserId ? User::query()->find($actorUserId) : null,
            );
        }

        $this->activityLogger->log(
            'proof_of_delivery',
            'POD diunggah.',
            $pod,
            'submitted',
            null,
            $actorUserId
        );

        return $pod->fresh();
    }

    public function verify(ProofOfDelivery $pod, ?string $notes, ?int $actorUserId = null): ProofOfDelivery
    {
        if (! $pod->canVerify()) {
            throw new \RuntimeException('POD tidak dapat diverifikasi pada status saat ini.');
        }

        $now = now();
        $pod->update([
            'status' => PodStatus::Verified,
            'verified_by' => $actorUserId,
            'verified_at' => $now,
            'verification_notes' => $notes,
        ]);

        $shipment = $pod->shipment;
        if ($shipment) {
            $shipment->update([
                'status' => 'completed',
                'completion_verified_at' => $now,
            ]);

            $shipment->trackings()->create([
                'status' => 'completed',
                'notes' => 'Completed',
                'tracked_at' => $now,
                'updated_by' => $actorUserId,
            ]);

            $this->shipmentActivityLogger->log(
                $shipment,
                'status_completed',
                'Shipment selesai setelah POD diterima.',
                $actorUserId ? User::query()->find($actorUserId) : null,
            );
        }

        $this->activityLogger->log(
            'proof_of_delivery',
            'POD diverifikasi.',
            $pod,
            'verified',
            null,
            $actorUserId
        );

        if ($shipment) {
            $this->activityLogger->log(
                'proof_of_delivery',
                'Shipment Completed.',
                $pod,
                'shipment_completed',
                null,
                $actorUserId
            );
        }

        return $pod->fresh();
    }

    public function reject(ProofOfDelivery $pod, ?string $notes, ?int $actorUserId = null): ProofOfDelivery
    {
        if (! $pod->canVerify()) {
            throw new \RuntimeException('POD tidak dapat ditolak pada status saat ini.');
        }

        $pod->update([
            'status' => PodStatus::Rejected,
            'verification_notes' => $notes,
            'verified_by' => $actorUserId,
            'verified_at' => now(),
        ]);

        $this->activityLogger->log(
            'proof_of_delivery',
            'POD ditolak.',
            $pod,
            'rejected',
            null,
            $actorUserId
        );

        return $pod->fresh();
    }

    public function createFromDeliveryCompletion(Shipment $shipment, ?int $actorUserId = null): void
    {
        $coverage = (string) ($shipment->shipment_coverage ?? '');
        if (! str_ends_with($coverage, 'door')) {
            return;
        }

        $this->ensureForShipment($shipment, $actorUserId);
    }
}
