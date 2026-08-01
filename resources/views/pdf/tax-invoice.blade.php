<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Tax Invoice {{ $invoice->invoice_number }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #333; margin: 0; padding: 0; }
        .container { padding: 24px; }
        .header { border-bottom: 2px solid #000; padding-bottom: 8px; margin-bottom: 16px; }
        .title { font-size: 18px; font-weight: bold; }
        .subtitle { font-size: 13px; color: #666; margin-top: 2px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        th, td { border: 1px solid #000; padding: 6px; text-align: left; }
        th { background: #f2f2f2; font-weight: bold; }
        .text-right { text-align: right; }
        .stamp { margin-top: 28px; font-size: 12px; color: #c00; font-weight: bold; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="title">TAX INVOICE (FAKTUR PAJAK)</div>
            <div class="subtitle">SOL Logistics Platform</div>
        </div>

        <table>
            <tr>
                <th style="width: 160px;">Tax Invoice No.</th>
                <td>TAX-{{ $invoice->invoice_number }}</td>
            </tr>
            <tr>
                <th>Issued Date</th>
                <td>{{ optional($invoice->updated_at)->format('d/m/Y') }}</td>
            </tr>
            <tr>
                <th>Related Invoice</th>
                <td>{{ $invoice->invoice_number }}</td>
            </tr>
            <tr>
                <th>Bill To</th>
                <td>
                    {{ $invoice->company?->name }}<br>
                    {{ $invoice->company?->address }}<br>
                    NPWP: {{ $invoice->company?->npwp ?? '—' }}
                </td>
            </tr>
        </table>

        <table>
            <thead>
                <tr>
                    <th>Description</th>
                    <th class="text-right">DPP</th>
                    <th class="text-right">PPN (11%)</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Logistics services — {{ $invoice->shipment?->shipment_number ?? '—' }}</td>
                    <td class="text-right">{{ number_format((float) $invoice->subtotal, 0, ',', '.') }}</td>
                    <td class="text-right">{{ number_format((float) $invoice->tax_amount, 0, ',', '.') }}</td>
                </tr>
            </tbody>
        </table>

        <table style="width: 320px; margin-left: auto;">
            <tr>
                <th>DPP</th>
                <td class="text-right">{{ number_format((float) $invoice->subtotal, 0, ',', '.') }}</td>
            </tr>
            <tr>
                <th>PPN (11%)</th>
                <td class="text-right">{{ number_format((float) $invoice->tax_amount, 0, ',', '.') }}</td>
            </tr>
        </table>

        <p class="stamp">
            Pajak telah dipungut dan dilaporkan sesuai peraturan perpajakan yang berlaku.
        </p>

        <p style="margin-top: 18px; font-size: 10px; color: #777;">
            Printed via SOL Platform on {{ now()->format('d/m/Y H:i') }}.
        </p>
    </div>
</body>
</html>
