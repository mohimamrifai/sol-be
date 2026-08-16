<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Job Order {{ $jobOrder->job_order_number }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #333; margin: 0; padding: 0; }
        .container { padding: 20px; }
        .header { border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 16px; }
        .title { font-size: 18px; font-weight: bold; }
        .subtitle { font-size: 12px; color: #666; margin-top: 4px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        th, td { border: 1px solid #000; padding: 6px; text-align: left; vertical-align: top; }
        th { background: #f2f2f2; width: 160px; }
        .section { font-size: 12px; font-weight: bold; margin: 14px 0 6px; }
        .footer { margin-top: 24px; font-size: 10px; text-align: center; color: #777; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="title">VENDOR JOB ORDER</div>
            <div class="subtitle">{{ $jobOrder->job_order_number }} · {{ $jobOrder->status?->label() ?? $jobOrder->status }}</div>
        </div>

        <div class="section">Vendor Information</div>
        <table>
            <tr><th>Vendor</th><td>{{ $vendorSnap['name'] ?? $jobOrder->vendor?->name ?? '—' }}</td></tr>
            <tr><th>Vendor Code</th><td>{{ $vendorSnap['code'] ?? $jobOrder->vendor?->code ?? '—' }}</td></tr>
            <tr><th>Vendor PIC</th><td>{{ $vendorSnap['pic_name'] ?? '—' }}</td></tr>
            <tr><th>Mobile</th><td>{{ $vendorSnap['pic_mobile'] ?? '—' }}</td></tr>
        </table>

        <div class="section">Shipment Information</div>
        <table>
            <tr><th>Shipment No</th><td>{{ $snap['shipment_number'] ?? $jobOrder->shipment?->shipment_number ?? '—' }}</td></tr>
            <tr><th>Consignment Note</th><td>{{ $snap['waybill_number'] ?? $jobOrder->shipment?->waybill_number ?? '—' }}</td></tr>
            <tr><th>Customer</th><td>{{ $snap['customer'] ?? $jobOrder->shipment?->company?->name ?? '—' }}</td></tr>
            <tr><th>Origin</th><td>{{ $snap['origin'] ?? '—' }}</td></tr>
            <tr><th>Destination</th><td>{{ $snap['destination'] ?? '—' }}</td></tr>
            <tr><th>Service</th><td>{{ $jobOrder->service_type?->label() ?? $jobOrder->service_type ?? '—' }}</td></tr>
        </table>

        <div class="section">Job Detail</div>
        <table>
            @if(($jobOrder->service_type?->value ?? $jobOrder->service_type) === 'pickup')
                <tr><th>Pickup Address</th><td>{{ $jobOrder->pickup_address ?? '—' }}</td></tr>
                <tr><th>Pickup Date</th><td>{{ $jobOrder->pickup_date?->format('d M Y H:i') ?? '—' }}</td></tr>
                <tr><th>Cargo Information</th><td>{{ $jobOrder->pickup_cargo_info ?? '—' }}</td></tr>
                <tr><th>Remark</th><td>{{ $jobOrder->pickup_remark ?? '—' }}</td></tr>
            @elseif(($jobOrder->service_type?->value ?? $jobOrder->service_type) === 'delivery')
                <tr><th>Delivery Address</th><td>{{ $jobOrder->delivery_address ?? '—' }}</td></tr>
                <tr><th>Delivery Date</th><td>{{ $jobOrder->delivery_date?->format('d M Y H:i') ?? '—' }}</td></tr>
                <tr><th>Cargo Information</th><td>{{ $jobOrder->delivery_cargo_info ?? '—' }}</td></tr>
                <tr><th>Remark</th><td>{{ $jobOrder->delivery_remark ?? '—' }}</td></tr>
            @else
                <tr><th>Origin Yard</th><td>{{ $jobOrder->originYard?->name ?? '—' }}</td></tr>
                <tr><th>Destination Yard</th><td>{{ $jobOrder->destinationYard?->name ?? '—' }}</td></tr>
                <tr><th>Train Schedule</th><td>{{ $jobOrder->train?->name ?? '—' }}</td></tr>
                <tr><th>Departure</th><td>{{ $jobOrder->departure_at?->format('d M Y H:i') ?? '—' }}</td></tr>
            @endif
        </table>

        <div class="section">Vehicle Assignment</div>
        <table>
            <tr><th>Vehicle Type</th><td>{{ $jobOrder->vehicle_type ?? '—' }}</td></tr>
            <tr><th>Vehicle Plate</th><td>{{ $jobOrder->vehicle_plate ?? '—' }}</td></tr>
            <tr><th>Driver Name</th><td>{{ $jobOrder->driver_name ?? '—' }}</td></tr>
            <tr><th>Driver Mobile</th><td>{{ $jobOrder->driver_mobile ?? '—' }}</td></tr>
        </table>

        <div class="section">Pricing Snapshot</div>
        <table>
            <tr><th>Vendor Rate</th><td>Rp {{ number_format((float) $jobOrder->vendor_rate, 0, ',', '.') }}</td></tr>
            <tr><th>Additional Cost</th><td>Rp {{ number_format((float) $jobOrder->additional_cost, 0, ',', '.') }}</td></tr>
            <tr><th>Total Cost</th><td>Rp {{ number_format((float) $jobOrder->total_cost, 0, ',', '.') }}</td></tr>
        </table>

        <div class="footer">Generated {{ now()->format('d M Y H:i') }} · PT SOL Logistics</div>
    </div>
</body>
</html>
