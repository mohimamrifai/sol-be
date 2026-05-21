<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Invoice {{ $invoice->invoice_number }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #f5f5f5; }
        .header { margin-bottom: 24px; }
        .text-right { text-align: right; }
        .total-row { font-weight: bold; }
        h1 { font-size: 18px; margin-bottom: 8px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>INVOICE</h1>
        <p><strong>No.</strong> {{ $invoice->invoice_number }}</p>
        <p>Tanggal terbit: {{ $invoice->issued_date?->format('d/m/Y') }}</p>
        <p>Jatuh tempo: {{ $invoice->due_date?->format('d/m/Y') }}</p>
    </div>

    <p><strong>Bill To:</strong></p>
    @if($invoice->relationLoaded('company') && $invoice->company)
        <p>{{ $invoice->company->name }}</p>
        <p>{{ $invoice->company->address }}</p>
        <p>{{ $invoice->company->email }} | {{ $invoice->company->phone }}</p>
    @else
        <p>Company ID: {{ $invoice->company_id }}</p>
    @endif

    @if($invoice->relationLoaded('shipment') && $invoice->shipment)
        <p style="margin-top: 16px;"><strong>Shipment:</strong> {{ $invoice->shipment->shipment_number }} / {{ $invoice->shipment->waybill_number }}</p>
    @endif

    <table>
        <thead>
            <tr>
                <th>Deskripsi</th>
                <th class="text-right">Qty</th>
                <th class="text-right">Harga Satuan</th>
                <th class="text-right">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->items ?? [] as $item)
                <tr>
                    <td>{{ $item->description }}</td>
                    <td class="text-right">{{ $item->quantity }}</td>
                    <td class="text-right">{{ number_format($item->unit_price, 0, ',', '.') }}</td>
                    <td class="text-right">{{ number_format($item->total_price ?? ($item->quantity * $item->unit_price), 0, ',', '.') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table style="width: 300px; margin-left: auto;">
        <tr>
            <td>Subtotal</td>
            <td class="text-right">{{ number_format($invoice->subtotal, 0, ',', '.') }}</td>
        </tr>
        <tr>
            <td>PPN (11%)</td>
            <td class="text-right">{{ number_format($invoice->tax_amount, 0, ',', '.') }}</td>
        </tr>
        <tr class="total-row">
            <td>Total</td>
            <td class="text-right">{{ number_format($invoice->total_amount, 0, ',', '.') }}</td>
        </tr>
    </table>

    @if($invoice->notes)
        <p style="margin-top: 20px;"><strong>Catatan:</strong> {{ $invoice->notes }}</p>
    @endif
</body>
</html>
