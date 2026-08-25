<?php

namespace Database\Seeders;

use App\Enums\AdminVendorInvoiceStatus;
use App\Enums\VendorJobOrderStatus;
use App\Models\User;
use App\Models\VendorInvoice;
use App\Models\VendorJobOrder;
use Illuminate\Database\Seeder;

class VendorAdminInvoiceSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(VendorSeeder::class);

        $actor = User::query()->where('email', 'operations@demo.internal.sol.test')->first()
            ?? User::query()->where('email', 'admin@customer.test')->first();

        $jobOrders = VendorJobOrder::query()
            ->where('status', VendorJobOrderStatus::Completed->value)
            ->whereDoesntHave('vendorInvoices', fn ($q) => $q->where('source', 'admin'))
            ->with('vendor')
            ->orderBy('id')
            ->limit(5)
            ->get();

        if ($jobOrders->count() < 5) {
            $shortfall = 5 - $jobOrders->count();
            $promoted = VendorJobOrder::query()
                ->whereIn('status', [
                    VendorJobOrderStatus::InProgress->value,
                    VendorJobOrderStatus::Sent->value,
                ])
                ->whereDoesntHave('vendorInvoices', fn ($q) => $q->where('source', 'admin'))
                ->orderBy('id')
                ->limit($shortfall)
                ->get();

            foreach ($promoted as $jo) {
                $jo->update([
                    'status' => VendorJobOrderStatus::Completed->value,
                    'completed_at' => now()->subDay(),
                ]);
                $jobOrders->push($jo->fresh('vendor'));
            }
        }

        if ($jobOrders->isEmpty()) {
            $this->command?->warn('VendorAdminInvoiceSeeder: tidak ada completed job order yang bisa ditagihkan. Jalankan CustomerDemoSeeder terlebih dahulu.');

            return;
        }

        $definitions = [
            ['status' => AdminVendorInvoiceStatus::Received, 'days_ago' => 5],
            ['status' => AdminVendorInvoiceStatus::UnderVerification, 'days_ago' => 4],
            ['status' => AdminVendorInvoiceStatus::ReadyForPayment, 'days_ago' => 3],
            ['status' => AdminVendorInvoiceStatus::Paid, 'days_ago' => 10],
            ['status' => AdminVendorInvoiceStatus::Rejected, 'days_ago' => 8],
        ];

        foreach ($definitions as $idx => $def) {
            $jo = $jobOrders[$idx] ?? null;
            if (! $jo) {
                break;
            }

            $extNum = 'VINV-DEMO-'.str_pad((string) ($idx + 1), 4, '0', STR_PAD_LEFT);
            $invoiceAmount = (float) ($jo->total_cost ?: 2_500_000);
            $tax = round($invoiceAmount * 0.11, 2);
            $total = $invoiceAmount + $tax;
            $status = $def['status']->value;
            $invoiceDate = now()->subDays($def['days_ago'])->toDateString();
            $reviewed = in_array($status, [
                AdminVendorInvoiceStatus::ReadyForPayment->value,
                AdminVendorInvoiceStatus::Paid->value,
                AdminVendorInvoiceStatus::Rejected->value,
            ], true);

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
                    'status' => $status,
                    'notes' => 'Demo admin vendor invoice ('.$status.')',
                    'file_path' => 'seed/vendor-invoices/'.$extNum.'.pdf',
                    'created_by' => $actor?->id,
                    'reviewed_by' => $reviewed ? $actor?->id : null,
                    'reviewed_at' => $reviewed ? now()->subDays(max(1, $def['days_ago'] - 1)) : null,
                    'rejection_reason' => $status === AdminVendorInvoiceStatus::Rejected->value
                        ? 'Nomor invoice tidak sesuai dengan PO.'
                        : null,
                ]
            );

            if (! $invoice->jobOrders()->where('vendor_job_orders.id', $jo->id)->exists()) {
                $invoice->jobOrders()->attach($jo->id, ['amount' => $invoiceAmount]);
            }
        }
    }
}
