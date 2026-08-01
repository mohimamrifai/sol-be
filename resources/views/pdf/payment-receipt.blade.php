<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Payment Receipt {{ $payment->midtrans_order_id }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #333; margin: 0; padding: 0; }
        .container { padding: 24px; }
        .header { border-bottom: 2px solid #000; padding-bottom: 8px; margin-bottom: 16px; }
        .title { font-size: 18px; font-weight: bold; }
        .subtitle { font-size: 13px; color: #666; margin-top: 2px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        th, td { border: 1px solid #000; padding: 6px; text-align: left; }
        th { background: #f2f2f2; font-weight: bold; width: 170px; }
        .text-right { text-align: right; }
        .total { font-size: 14px; font-weight: bold; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="title">PAYMENT RECEIPT</div>
            <div class="subtitle">SOL Logistics Platform</div>
        </div>

        <table>
            <tr>
                <th>Receipt No.</th>
                <td>RCP-{{ str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT) }}</td>
            </tr>
            <tr>
                <th>Order ID (Midtrans)</th>
                <td>{{ $payment->midtrans_order_id }}</td>
            </tr>
            <tr>
                <th>Transaction ID</th>
                <td>{{ $payment->midtrans_transaction_id ?? '—' }}</td>
            </tr>
            <tr>
                <th>Payment Type</th>
                <td>{{ strtoupper((string) $payment->payment_type) }}</td>
            </tr>
            <tr>
                <th>Status</th>
                <td><strong>PAID</strong></td>
            </tr>
            <tr>
                <th>Paid At</th>
                <td>{{ optional($payment->paid_at)->format('d/m/Y H:i') ?? '—' }}</td>
            </tr>
        </table>

        <table>
            <tr>
                <th>Bill To</th>
                <td>
                    {{ $payment->invoice?->company?->name }}<br>
                    {{ $payment->invoice?->company?->address }}
                </td>
            </tr>
            <tr>
                <th>Related Invoice</th>
                <td>{{ $payment->invoice?->invoice_number }}</td>
            </tr>
            <tr>
                <th>Related Shipment</th>
                <td>{{ $payment->invoice?->shipment?->shipment_number ?? '—' }}</td>
            </tr>
        </table>

        <table>
            <tr>
                <th>Description</th>
                <th class="text-right">Amount (IDR)</th>
            </tr>
            <tr>
                <td>Payment for Invoice {{ $payment->invoice?->invoice_number }}</td>
                <td class="text-right total">{{ number_format((float) $payment->amount, 0, ',', '.') }}</td>
            </tr>
        </table>

        <p style="margin-top: 18px; font-size: 10px; color: #777;">
            This receipt is generated automatically by the SOL Platform.
            Printed on {{ now()->format('d/m/Y H:i') }}.
        </p>
    </div>
</body>
</html>
