<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Delivery Order {{ $shipment->shipment_number }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #333; margin: 0; padding: 0; }
        .container { padding: 24px; }
        .header { border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 20px; }
        .title { font-size: 18px; font-weight: bold; }
        .subtitle { font-size: 13px; color: #666; margin-top: 2px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        th, td { border: 1px solid #000; padding: 6px; text-align: left; vertical-align: top; }
        th { background: #f2f2f2; width: 150px; }
        .section-title { font-size: 12px; font-weight: bold; background: #eee; padding: 4px 8px; border: 1px solid #000; border-bottom: none; margin-top: 12px; }
        .footer { margin-top: 30px; font-size: 10px; text-align: center; color: #777; border-top: 1px solid #ddd; padding-top: 10px; }
        .signatures { margin-top: 40px; }
        .signatures td { border: none; text-align: center; padding: 0 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="title">DELIVERY ORDER</div>
            <div class="subtitle">SOL Logistics Platform</div>
            <p style="margin-top: 8px;">
                <strong>DO Number:</strong> DO-{{ str_pad((string) $shipment->id, 6, '0', STR_PAD_LEFT) }}<br>
                <strong>Shipment No:</strong> {{ $shipment->shipment_number }}<br>
                <strong>CN Number:</strong> {{ $shipment->waybill_number }}<br>
                <strong>Issued:</strong> {{ now()->format('d/m/Y H:i') }}
            </p>
        </div>

        <div class="section-title">RECEIVER (PENERIMA)</div>
        <table>
            <tr>
                <th>Name</th>
                <td>{{ data_get($shipment->consignee_snapshot, 'name', $shipment->booking?->consignee_name) ?? '—' }}</td>
            </tr>
            <tr>
                <th>Address</th>
                <td>{{ data_get($shipment->consignee_snapshot, 'address', $shipment->booking?->consignee_address) ?? '—' }}</td>
            </tr>
            <tr>
                <th>Phone</th>
                <td>{{ data_get($shipment->consignee_snapshot, 'phone', $shipment->booking?->consignee_phone) ?? '—' }}</td>
            </tr>
        </table>

        <div class="section-title">SHIPMENT</div>
        <table>
            <tr>
                <th>Service Type</th>
                <td>{{ $shipment->serviceType?->name ?? '—' }}</td>
            </tr>
            <tr>
                <th>Origin</th>
                <td>{{ $shipment->originLocation?->name ?? '—' }} ({{ $shipment->originLocation?->code ?? '—' }})</td>
            </tr>
            <tr>
                <th>Destination</th>
                <td>{{ $shipment->destinationLocation?->name ?? '—' }} ({{ $shipment->destinationLocation?->code ?? '—' }})</td>
            </tr>
            <tr>
                <th>Cargo Category</th>
                <td>{{ $shipment->booking?->cargoCategory?->name ?? '—' }}</td>
            </tr>
            <tr>
                <th>Current Status</th>
                <td>{{ ucwords(str_replace('_', ' ', (string) $shipment->status)) }}</td>
            </tr>
        </table>

        <p style="margin-top: 18px;">
            Dengan ini, barang kiriman siap untuk diambil / diantar kepada penerima
            setelah seluruh dokumen dan pembayaran diselesaikan.
        </p>

        <table class="signatures">
            <tr>
                <td>
                    Shipper<br><br><br><br>
                    (____________________)
                </td>
                <td>
                    Operations<br><br><br><br>
                    (____________________)
                </td>
                <td>
                    Consignee<br><br><br><br>
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
