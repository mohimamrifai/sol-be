<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Consignment Note {{ $shipment->waybill_number }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #333; margin: 0; padding: 0; }
        .container { padding: 20px; }
        .header { border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 20px; }
        .header table { width: 100%; border: none; }
        .header td { border: none; padding: 0; vertical-align: top; }
        .title { font-size: 18px; font-weight: bold; margin-bottom: 4px; }
        .subtitle { font-size: 14px; color: #666; }
        
        .qr-section { text-align: right; }
        .qr-code { width: 100px; height: 100px; }

        table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
        th, td { border: 1px solid #000; padding: 6px; text-align: left; vertical-align: top; }
        th { background: #f2f2f2; font-weight: bold; width: 150px; }

        .section-title { font-size: 12px; font-weight: bold; background: #eee; padding: 4px 8px; border: 1px solid #000; border-bottom: none; margin-top: 10px; }
        
        .footer { margin-top: 30px; font-size: 10px; text-align: center; color: #777; border-top: 1px solid #ddd; padding-top: 10px; }
        
        .grid { width: 100%; }
        .grid td { width: 50%; }
        
        .label { font-weight: bold; margin-bottom: 2px; display: block; }
        .value { margin-bottom: 8px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <table>
                <tr>
                    <td>
                        <div class="title">CONSIGNMENT NOTE (CN)</div>
                        <div class="subtitle">Logistics & Freight Services</div>
                        <p style="margin-top: 10px;">
                            <strong>CN Number:</strong> {{ $shipment->waybill_number }}<br>
                            <strong>Shipment Ref:</strong> {{ $shipment->shipment_number }}<br>
                            <strong>Date:</strong> {{ now()->format('d/m/Y') }}
                        </p>
                    </td>
                    <td class="qr-section">
                        @php
                            $qrData = route('public.tracking', ['waybill' => $shipment->waybill_number]);
                            $qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode($qrData);
                        @endphp
                        <img src="{{ $qrUrl }}" class="qr-code" alt="QR Code">
                    </td>
                </tr>
            </table>
        </div>

        <table class="grid">
            <tr>
                <td>
                    <div class="label">SHIPPER (PENGIRIM)</div>
                    <div class="value">
                        <strong>{{ $shipment->booking->shipper_name ?? '-' }}</strong><br>
                        {{ $shipment->booking->shipper_address ?? '-' }}<br>
                        Telp: {{ $shipment->booking->shipper_phone ?? '-' }}
                    </div>
                </td>
                <td>
                    <div class="label">CONSIGNEE (PENERIMA)</div>
                    <div class="value">
                        <strong>{{ $shipment->booking->consignee_name ?? '-' }}</strong><br>
                        {{ $shipment->booking->consignee_address ?? '-' }}<br>
                        Telp: {{ $shipment->booking->consignee_phone ?? '-' }}
                    </div>
                </td>
            </tr>
        </table>

        <table>
            <tr>
                <th>Service Type</th>
                <td>{{ $shipment->serviceType->name ?? '-' }}</td>
                <th>Cargo Category</th>
                <td>{{ $shipment->booking->cargoCategory->name ?? '-' }}</td>
            </tr>
            <tr>
                <th>Origin</th>
                <td>{{ $shipment->originLocation->name ?? '-' }} ({{ $shipment->originLocation->code ?? '-' }})</td>
                <th>Destination</th>
                <td>{{ $shipment->destinationLocation->name ?? '-' }} ({{ $shipment->destinationLocation->code ?? '-' }})</td>
            </tr>
        </table>

        <div class="section-title">CARGO DETAILS</div>
        <table>
            <thead>
                <tr>
                    <th style="width: 30px;">No</th>
                    <th>Item Description</th>
                    <th style="width: 60px;">Qty</th>
                    <th style="width: 80px;">Weight (kg)</th>
                    <th style="width: 100px;">Dimensions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($shipment->items as $idx => $item)
                    <tr>
                        <td>{{ $idx + 1 }}</td>
                        <td>
                            <strong>{{ $item->name }}</strong>
                            @if($item->description)<br><small>{{ $item->description }}</small>@endif
                        </td>
                        <td>{{ $item->quantity }}</td>
                        <td>{{ number_format($item->gross_weight, 2) }}</td>
                        <td>
                            @if($item->length || $item->width || $item->height)
                                {{ $item->length ?? 0 }}x{{ $item->width ?? 0 }}x{{ $item->height ?? 0 }}
                            @else
                                -
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" style="text-align: center;">No item details available.</td>
                    </tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="2" style="text-align: right;">TOTAL</th>
                    <th>{{ $shipment->items->sum('quantity') }}</th>
                    <th>{{ number_format($shipment->items->sum('gross_weight'), 2) }}</th>
                    <th>-</th>
                </tr>
            </tfoot>
        </table>

        @if($shipment->booking && $shipment->booking->cargo_description)
            <div style="margin-top: 10px;">
                <strong>Cargo Description:</strong><br>
                <div style="border: 1px solid #000; padding: 6px; min-height: 30px; background: #fafafa;">
                    {{ $shipment->booking->cargo_description }}
                </div>
            </div>
        @endif

        @if($shipment->is_dangerous_goods || $shipment->temperature)
            <div style="margin-top: 10px; border: 1px solid #c00; padding: 8px; background: #fff5f5;">
                <strong style="color: #c00;">SPECIAL HANDLING / DG INFO:</strong><br>
                @if($shipment->is_dangerous_goods)
                    DG Class: {{ $shipment->dgClass->name ?? $shipment->dg_class_id }} | 
                    UN Number: {{ $shipment->un_number ?? '-' }}
                    @if($shipment->equipment_condition)
                        | Condition: {{ $shipment->equipment_condition }}
                    @endif
                    <br>
                @endif
                @if($shipment->temperature)
                    Target Temperature: {{ $shipment->temperature }} °C
                @endif
            </div>
        @endif

        @if($shipment->notes)
            <div style="margin-top: 10px;">
                <strong>Admin Notes:</strong><br>
                <div style="border: 1px solid #000; padding: 6px; min-height: 40px;">
                    {{ $shipment->notes }}
                </div>
            </div>
        @endif

        <table style="margin-top: 30px; border: none;">
            <tr style="border: none;">
                <td style="border: none; text-align: center; width: 33%;">
                    Shipper Signature<br><br><br><br>
                    (____________________)
                </td>
                <td style="border: none; text-align: center; width: 33%;">
                    Carrier Authority<br><br><br><br>
                    (____________________)
                </td>
                <td style="border: none; text-align: center; width: 33%;">
                    Consignee Signature<br><br><br><br>
                    (____________________)
                </td>
            </tr>
        </table>

        <div class="footer">
            Printed via SOL Platform on {{ now()->format('d/m/Y H:i') }}
        </div>
    </div>
</body>
</html>
