@php
    use App\Services\InvoicePdfPresenter;

    $companyName = $company['name'] ?? '—';
    $companyCode = $company['company_code'] ?? null;
    $companyAddress = trim(implode(', ', array_filter([
        $company['address'] ?? null,
        $company['city'] ?? null,
        $company['province'] ?? null,
        $company['postal_code'] ?? null,
    ])));
    $paymentTerms = $company['payment_terms'] ?? $company['payment_term'] ?? '—';
    $currency = $company['currency'] ?? 'IDR';
    $route = trim(($shipment['origin'] ?? '—').' → '.($shipment['destination'] ?? '—'));
    $coverage = InvoicePdfPresenter::formatCoverage($shipment['shipment_coverage'] ?? null);
    $statusLabel = match ($invoice->status) {
        'paid' => 'Paid',
        'partially_paid' => 'Partially Paid',
        'issued', 'unpaid' => 'Issued',
        'draft' => 'Draft',
        'cancelled' => 'Cancelled',
        default => ucfirst(str_replace('_', ' ', (string) $invoice->status)),
    };
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Invoice {{ $invoice->invoice_number }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #222; margin: 0; padding: 0; }
        .container { padding: 24px; }
        .header { border-bottom: 2px solid #111; padding-bottom: 10px; margin-bottom: 18px; }
        .title { font-size: 20px; font-weight: bold; letter-spacing: 0.5px; }
        .subtitle { font-size: 12px; color: #666; margin-top: 4px; }
        .meta-grid { width: 100%; margin-bottom: 18px; }
        .meta-grid td { vertical-align: top; padding: 0; border: 0; }
        .meta-right { text-align: right; }
        .section-title { font-size: 12px; font-weight: bold; text-transform: uppercase; margin: 18px 0 8px; color: #444; }
        table.data { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        table.data th, table.data td { border: 1px solid #bbb; padding: 7px 8px; text-align: left; }
        table.data th { background: #f3f3f3; font-weight: bold; }
        .text-right { text-align: right; }
        .summary { width: 280px; margin-left: auto; }
        .summary td { border: 1px solid #bbb; padding: 7px 8px; }
        .summary .total td { font-weight: bold; background: #f8f8f8; }
        .muted { color: #666; }
        .status-paid { color: #0a7a2f; font-weight: bold; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="title">CUSTOMER INVOICE</div>
            <div class="subtitle">SOL Logistics Platform</div>
        </div>

        <table class="meta-grid">
            <tr>
                <td style="width: 55%;">
                    <strong>Invoice No.</strong><br>
                    {{ $invoice->invoice_number }}<br><br>
                    <strong>Customer</strong><br>
                    {{ $companyName }}@if($companyCode) ({{ $companyCode }})@endif<br>
                    @if($companyAddress !== '')
                        <span class="muted">{{ $companyAddress }}</span><br>
                    @endif
                    @if(!empty($company['npwp']))
                        <span class="muted">NPWP: {{ $company['npwp'] }}</span>
                    @endif
                </td>
                <td class="meta-right" style="width: 45%;">
                    <strong>Invoice Date</strong><br>
                    {{ $invoice->issued_date?->format('d/m/Y') ?? '—' }}<br><br>
                    <strong>Due Date</strong><br>
                    {{ $invoice->due_date?->format('d/m/Y') ?? '—' }}<br><br>
                    <strong>Status</strong><br>
                    <span class="{{ $invoice->status === 'paid' ? 'status-paid' : '' }}">{{ $statusLabel }}</span>
                </td>
            </tr>
        </table>

        <div class="section-title">Invoice Information</div>
        <table class="data">
            <tr>
                <th>Currency</th>
                <td>{{ $currency }}</td>
                <th>Payment Terms</th>
                <td>{{ $paymentTerms }}</td>
            </tr>
            @if($invoice->notes)
                <tr>
                    <th>Invoice Remark</th>
                    <td colspan="3">{{ $invoice->notes }}</td>
                </tr>
            @endif
        </table>

        <div class="section-title">Shipment Information</div>
        <table class="data">
            <thead>
                <tr>
                    <th>Shipment No</th>
                    <th>CN No</th>
                    <th>Route</th>
                    <th>Service</th>
                    <th>Shipment Coverage</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>{{ $shipment['shipment_no'] ?? '—' }}</td>
                    <td>{{ $shipment['cn_no'] ?? '—' }}</td>
                    <td>{{ $route }}</td>
                    <td>{{ $shipment['service_type'] ?? '—' }}</td>
                    <td>{{ $coverage }}</td>
                </tr>
            </tbody>
        </table>

        <div class="section-title">Invoice Detail</div>
        <table class="data">
            <thead>
                <tr>
                    <th>Description</th>
                    <th class="text-right">Qty</th>
                    <th class="text-right">Unit Price</th>
                    <th class="text-right">Amount</th>
                </tr>
            </thead>
            <tbody>
                @forelse($items as $item)
                    <tr>
                        <td>{{ $item->description }}</td>
                        <td class="text-right">{{ $item->quantity }}</td>
                        <td class="text-right">{{ InvoicePdfPresenter::formatMoney($item->unit_price) }}</td>
                        <td class="text-right">{{ InvoicePdfPresenter::formatMoney($item->total_price ?? ($item->quantity * $item->unit_price)) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="muted">Tidak ada item invoice.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <table class="summary">
            <tr>
                <td>Subtotal</td>
                <td class="text-right">{{ InvoicePdfPresenter::formatMoney($summary['subtotal']) }}</td>
            </tr>
            <tr>
                <td>Discount</td>
                <td class="text-right">{{ InvoicePdfPresenter::formatMoney($summary['discount']) }}</td>
            </tr>
            <tr>
                <td>PPN</td>
                <td class="text-right">{{ InvoicePdfPresenter::formatMoney($summary['ppn']) }}</td>
            </tr>
            <tr class="total">
                <td>Grand Total</td>
                <td class="text-right">{{ InvoicePdfPresenter::formatMoney($summary['grand_total']) }}</td>
            </tr>
        </table>

        <div class="section-title">Payment Summary</div>
        <table class="data">
            <tr>
                <th>Invoice Amount</th>
                <td>{{ InvoicePdfPresenter::formatMoney($payment_summary['invoice_amount']) }}</td>
                <th>Paid Amount</th>
                <td>{{ InvoicePdfPresenter::formatMoney($payment_summary['paid_amount']) }}</td>
            </tr>
            <tr>
                <th>Outstanding Amount</th>
                <td colspan="3">{{ InvoicePdfPresenter::formatMoney($payment_summary['outstanding_amount']) }}</td>
            </tr>
        </table>

        @if(count($payment_history) > 0)
            <div class="section-title">Payment History</div>
            <table class="data">
                <thead>
                    <tr>
                        <th>Payment Date</th>
                        <th class="text-right">Amount</th>
                        <th>Method</th>
                        <th>Reference No</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($payment_history as $payment)
                        <tr>
                            <td>{{ optional($payment['payment_date'])->format('d/m/Y') ?? '—' }}</td>
                            <td class="text-right">{{ InvoicePdfPresenter::formatMoney($payment['amount']) }}</td>
                            <td>{{ strtoupper((string) ($payment['method'] ?? '—')) }}</td>
                            <td>{{ $payment['reference_number'] ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        <p class="muted" style="margin-top: 24px; font-size: 10px;">
            Dokumen ini dibuat otomatis oleh SOL Platform pada {{ now()->format('d/m/Y H:i') }}.
        </p>
    </div>
</body>
</html>
