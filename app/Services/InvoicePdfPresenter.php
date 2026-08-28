<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Invoice;

final class InvoicePdfPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function present(Invoice $invoice): array
    {
        $invoice->loadMissing([
            'items',
            'payments' => fn ($query) => $query
                ->whereIn('status', ['success', 'settlement'])
                ->orderByDesc('paid_at')
                ->orderByDesc('id'),
        ]);

        $company = is_array($invoice->company_snapshot) ? $invoice->company_snapshot : [];
        $shipment = is_array($invoice->shipment_snapshot) ? $invoice->shipment_snapshot : [];
        $summary = $this->summary($invoice);
        $paidAmount = $invoice->paidAmount();

        return [
            'invoice' => $invoice,
            'company' => $company,
            'shipment' => $shipment,
            'items' => $invoice->items,
            'summary' => $summary,
            'payment_summary' => [
                'invoice_amount' => (float) $invoice->total_amount,
                'paid_amount' => $paidAmount,
                'outstanding_amount' => max((float) $invoice->total_amount - $paidAmount, 0),
            ],
            'payment_history' => $invoice->payments->map(fn ($payment) => [
                'payment_date' => $payment->paid_at ?? $payment->created_at,
                'amount' => (float) $payment->amount,
                'method' => $payment->method ?? $payment->payment_type,
                'reference_number' => $payment->manual_reference_number
                    ?? $payment->midtrans_transaction_id
                    ?? $payment->midtrans_order_id,
            ])->values()->all(),
        ];
    }

    /**
     * @return array{subtotal: float, discount: float, ppn: float, grand_total: float}
     */
    private function summary(Invoice $invoice): array
    {
        $subtotal = round((float) $invoice->subtotal, 2);
        $tax = round((float) $invoice->tax_amount, 2);
        $grandTotal = round((float) $invoice->total_amount, 2);
        $netBeforeTax = max(0.0, round($grandTotal - $tax, 2));

        return [
            'subtotal' => $subtotal,
            'discount' => max(0.0, round($subtotal - $netBeforeTax, 2)),
            'ppn' => $tax,
            'grand_total' => $grandTotal,
        ];
    }

    public static function formatCoverage(?string $coverage): string
    {
        return match (strtolower((string) $coverage)) {
            'port_to_port' => 'Port to Port',
            'door_to_port' => 'Door to Port',
            'port_to_door' => 'Port to Door',
            'door_to_door' => 'Door to Door',
            default => $coverage ? str_replace('_', ' ', ucwords(str_replace('_', ' ', $coverage))) : '—',
        };
    }

    public static function formatMoney(float|int|string|null $value): string
    {
        $amount = (float) $value;

        return 'Rp '.number_format($amount, 0, ',', '.');
    }
}
