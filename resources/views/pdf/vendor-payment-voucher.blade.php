<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Payment Voucher {{ $payment['payment_number'] ?? '' }}</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; color: #111; }
        h1 { font-size: 20px; margin-bottom: 4px; }
        .muted { color: #666; font-size: 13px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        td, th { border: 1px solid #ddd; padding: 8px; text-align: left; font-size: 13px; }
        th { background: #f5f5f5; }
    </style>
</head>
<body>
    <h1>Vendor Payment Voucher</h1>
    <p class="muted">{{ $payment['payment_number'] ?? '—' }}</p>
    <table>
        <tr><th>Vendor</th><td>{{ $payment['vendor_name'] ?? $payment['vendor'] ?? '—' }}</td></tr>
        <tr><th>Vendor Invoice No</th><td>{{ $payment['vendor_invoice_no'] ?? '—' }}</td></tr>
        <tr><th>Invoice Amount</th><td>Rp {{ number_format((float) ($payment['invoice_amount'] ?? 0), 0, ',', '.') }}</td></tr>
        <tr><th>Paid Amount</th><td>Rp {{ number_format((float) ($payment['paid_amount'] ?? 0), 0, ',', '.') }}</td></tr>
        <tr><th>Status</th><td>{{ $payment['status'] ?? '—' }}</td></tr>
    </table>
    @if (!empty($payment['payment_history']))
        <h2 style="font-size:16px;margin-top:24px;">Payment History</h2>
        <table>
            <thead><tr><th>Date</th><th>Amount</th><th>Method</th><th>Reference</th></tr></thead>
            <tbody>
                @foreach ($payment['payment_history'] as $row)
                    <tr>
                        <td>{{ $row['payment_date'] ?? '—' }}</td>
                        <td>Rp {{ number_format((float) ($row['amount'] ?? 0), 0, ',', '.') }}</td>
                        <td>{{ $row['method'] ?? '—' }}</td>
                        <td>{{ $row['reference_no'] ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
