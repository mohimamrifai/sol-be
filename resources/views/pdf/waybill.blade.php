<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Waybill {{ $shipment->waybill_number }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        th, td { border: 1px solid #ddd; padding: 6px; text-align: left; }
        th { background: #f5f5f5; width: 140px; }
        .header { margin-bottom: 20px; }
        h1 { font-size: 18px; margin-bottom: 4px; }
        .timeline { margin-top: 16px; }
        .timeline-item { margin-bottom: 8px; padding: 6px; border-left: 3px solid #333; padding-left: 10px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>WAYBILL</h1>
        <p><strong>No. Waybill:</strong> {{ $shipment->waybill_number }}</p>
        <p><strong>No. Shipment:</strong> {{ $shipment->shipment_number }}</p>
        <p><strong>Status:</strong> {{ $shipment->status }}</p>
    </div>

    <table>
        <tr>
            <th>Origin</th>
            <td>{{ $shipment->relationLoaded('originLocation') && $shipment->originLocation ? $shipment->originLocation->name . ($shipment->originLocation->code ? ' (' . $shipment->originLocation->code . ')' : '') : '-' }}</td>
        </tr>
        <tr>
            <th>Destination</th>
            <td>{{ $shipment->relationLoaded('destinationLocation') && $shipment->destinationLocation ? $shipment->destinationLocation->name . ($shipment->destinationLocation->code ? ' (' . $shipment->destinationLocation->code . ')' : '') : '-' }}</td>
        </tr>
        <tr>
            <th>Estimasi Keberangkatan</th>
            <td>{{ $shipment->estimated_departure?->format('d/m/Y') ?? '-' }}</td>
        </tr>
        <tr>
            <th>Estimasi Tiba</th>
            <td>{{ $shipment->estimated_arrival?->format('d/m/Y') ?? '-' }}</td>
        </tr>
    </table>

    <h2 style="font-size: 14px;">Timeline</h2>
    <div class="timeline">
        @forelse($shipment->trackings ?? [] as $t)
            <div class="timeline-item">
                <strong>{{ $t->status }}</strong>
                @if($t->tracked_at) — {{ $t->tracked_at->format('d/m/Y H:i') }} @endif
                @if($t->notes) <br><small>{{ $t->notes }}</small> @endif
            </div>
        @empty
            <p>-</p>
        @endforelse
    </div>

    @if($shipment->notes)
        <p style="margin-top: 16px;"><strong>Catatan:</strong> {{ $shipment->notes }}</p>
    @endif
</body>
</html>
