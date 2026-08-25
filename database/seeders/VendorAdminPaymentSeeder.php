<?php

namespace Database\Seeders;

use App\Enums\AdminVendorInvoiceStatus;
use App\Enums\AdminVendorPaymentRequestStatus;
use App\Enums\VendorJobOrderStatus;
use App\Enums\VendorPaymentStatus;
use App\Models\User;
use App\Models\VendorInvoice;
use App\Models\VendorJobOrder;
use App\Models\VendorPayment;
use App\Models\VendorPaymentRequest;
use Illuminate\Database\Seeder;

class VendorAdminPaymentSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(VendorAdminInvoiceSeeder::class);

        $actor = User::query()->where('email', 'operations@demo.internal.sol.test')->first()
            ?? User::query()->where('email', 'admin@customer.test')->first();

        $finance = User::query()->where('email', 'finance@demo.internal.sol.test')->first() ?? $actor;

        $this->ensurePaymentDemoInvoices($actor);

        $invoices = VendorInvoice::query()
            ->where('source', 'admin')
            ->whereIn('status', [
                AdminVendorInvoiceStatus::ReadyForPayment->value,
                AdminVendorInvoiceStatus::Paid->value,
            ])
            ->with('vendor')
            ->orderBy('id')
            ->get();

        if ($invoices->isEmpty()) {
            $this->command?->warn('VendorAdminPaymentSeeder: tidak ada admin invoice siap bayar. Jalankan VendorAdminInvoiceSeeder terlebih dahulu.');

            return;
        }

        $definitions = [
            [
                'payment_number' => 'VPAY-DEMO-0001',
                'status' => AdminVendorPaymentRequestStatus::WaitingApproval,
                'invoice_index' => 0,
                'paid_amount' => 0,
                'payment' => null,
            ],
            [
                'payment_number' => 'VPAY-DEMO-0002',
                'status' => AdminVendorPaymentRequestStatus::ReadyToPay,
                'invoice_index' => 1,
                'paid_amount' => 0,
                'approved' => true,
                'payment' => null,
            ],
            [
                'payment_number' => 'VPAY-DEMO-0003',
                'status' => AdminVendorPaymentRequestStatus::Paid,
                'invoice_index' => 2,
                'paid_amount' => null,
                'approved' => true,
                'payment' => [
                    'payment_number' => 'PAY-V-DEMO-0001',
                    'payment_method' => 'bank_transfer',
                    'company_bank' => 'BCA - 1234567890',
                    'days_ago' => 7,
                ],
            ],
            [
                'payment_number' => 'VPAY-DEMO-0004',
                'status' => AdminVendorPaymentRequestStatus::Cancelled,
                'invoice_index' => 0,
                'paid_amount' => 0,
                'approval_remark' => 'Dokumen pendukung tidak lengkap.',
                'payment' => null,
            ],
        ];

        foreach ($definitions as $def) {
            $invoice = $invoices[$def['invoice_index'] % $invoices->count()] ?? null;
            if (! $invoice) {
                continue;
            }

            $approvedAmount = (float) $invoice->total_amount;
            $paidAmount = $def['paid_amount'] ?? $approvedAmount;

            $request = VendorPaymentRequest::updateOrCreate(
                ['payment_number' => $def['payment_number']],
                [
                    'vendor_invoice_id' => $invoice->id,
                    'status' => $def['status']->value,
                    'approval_status' => $def['status']->value,
                    'approved_by' => ($def['approved'] ?? false) ? $finance?->id : null,
                    'approved_at' => ($def['approved'] ?? false) ? now()->subDays(5) : null,
                    'approval_remark' => $def['approval_remark'] ?? null,
                    'vendor_snapshot' => $this->vendorSnapshot($invoice),
                    'invoice_amount' => $approvedAmount,
                    'approved_amount' => $approvedAmount,
                    'paid_amount' => $paidAmount,
                    'created_by' => $actor?->id,
                ]
            );

            if ($def['status'] === AdminVendorPaymentRequestStatus::Paid && $def['payment']) {
                $paymentDate = now()->subDays($def['payment']['days_ago'])->toDateString();

                VendorPayment::updateOrCreate(
                    ['payment_number' => $def['payment']['payment_number']],
                    [
                        'vendor_invoice_id' => $invoice->id,
                        'vendor_payment_request_id' => $request->id,
                        'amount' => $approvedAmount,
                        'payment_date' => $paymentDate,
                        'payment_method' => $def['payment']['payment_method'],
                        'company_bank' => $def['payment']['company_bank'],
                        'reference_no' => 'TRF-DEMO-'.str_pad((string) $request->id, 4, '0', STR_PAD_LEFT),
                        'status' => VendorPaymentStatus::Paid->value,
                        'paid_by' => $finance?->id,
                        'notes' => 'Demo vendor payment (paid).',
                    ]
                );

                $invoice->update(['status' => AdminVendorInvoiceStatus::Paid->value]);
            }
        }
    }

    private function ensurePaymentDemoInvoices(?User $actor): void
    {
        $existing = VendorInvoice::query()
            ->where('source', 'admin')
            ->whereIn('status', [
                AdminVendorInvoiceStatus::ReadyForPayment->value,
                AdminVendorInvoiceStatus::Paid->value,
            ])
            ->count();

        if ($existing >= 3) {
            return;
        }

        $needed = 3 - $existing;
        $jobOrders = VendorJobOrder::query()
            ->where('status', VendorJobOrderStatus::Completed->value)
            ->whereDoesntHave('vendorInvoices', fn ($q) => $q->where('source', 'admin'))
            ->with('vendor')
            ->orderBy('id')
            ->limit($needed)
            ->get();

        foreach ($jobOrders as $idx => $jo) {
            $extNum = 'VINV-PAY-DEMO-'.str_pad((string) ($idx + 1), 4, '0', STR_PAD_LEFT);
            $invoiceAmount = (float) ($jo->total_cost ?: 3_000_000);
            $tax = round($invoiceAmount * 0.11, 2);
            $total = $invoiceAmount + $tax;
            $invoiceDate = now()->subDays(6 - $idx)->toDateString();

            $invoice = VendorInvoice::updateOrCreate(
                [
                    'source' => 'admin',
                    'vendor_external_number' => $extNum,
                ],
                [
                    'vendor_id' => $jo->vendor_id,
                    'shipment_id' => $jo->shipment_id,
                    'invoice_date' => $invoiceDate,
                    'receive_date' => $invoiceDate,
                    'due_date' => now()->addDays(30)->toDateString(),
                    'invoice_amount' => $invoiceAmount,
                    'tax_amount' => $tax,
                    'total_amount' => $total,
                    'currency' => 'IDR',
                    'status' => AdminVendorInvoiceStatus::ReadyForPayment->value,
                    'notes' => 'Demo admin vendor invoice untuk payment report.',
                    'file_path' => 'seed/vendor-invoices/'.$extNum.'.pdf',
                    'created_by' => $actor?->id,
                    'reviewed_by' => $actor?->id,
                    'reviewed_at' => now()->subDays(5 - $idx),
                ]
            );

            if (! $invoice->jobOrders()->where('vendor_job_orders.id', $jo->id)->exists()) {
                $invoice->jobOrders()->attach($jo->id, ['amount' => $invoiceAmount]);
            }
        }
    }

    private function vendorSnapshot(VendorInvoice $invoice): ?array
    {
        $vendor = $invoice->vendor;
        if (! $vendor) {
            return null;
        }

        $types = is_array($vendor->vendor_types) ? $vendor->vendor_types : [];

        return [
            'code' => $vendor->code,
            'name' => $vendor->name,
            'vendor_category' => $vendor->vendor_category,
            'vendor_types' => $types,
            'payment_terms' => $vendor->payment_terms,
            'bank_name' => $vendor->bank_name,
            'bank_account_number' => $vendor->bank_account_number,
            'account_holder' => $vendor->account_holder,
        ];
    }
}
