<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Human-readable labels for admin report exports (Excel/PDF).
 * Mirrors admin UI English labels in sol-frontend AdminCommon.status.*.
 */
final class AdminReportLabelFormatter
{
    public static function shipmentCoverage(?string $coverage): string
    {
        return InvoicePdfPresenter::formatCoverage($coverage);
    }

    public static function bookingStatus(?string $status): string
    {
        return self::fromMap($status, [
            'draft' => 'Draft',
            'submitted' => 'Submitted',
            'under_review' => 'Under Review',
            'approved' => 'Confirmed',
            'confirmed' => 'Confirmed',
            'rejected' => 'Rejected',
            'cancelled' => 'Cancelled',
            'converted' => 'Converted to Shipment',
        ]);
    }

    public static function shipmentStatus(?string $status): string
    {
        return self::fromMap($status, [
            'booking_created' => 'Booking Created',
            'created' => 'Created',
            'survey_completed' => 'Survey Completed',
            'cargo_received' => 'Cargo Received',
            'stuffing_container' => 'Stuffing Container',
            'container_sealed' => 'Container Sealed',
            'train_departed' => 'Train Departed',
            'departed' => 'Departed',
            'train_arrived' => 'Train Arrived',
            'arrived' => 'Arrived',
            'container_unloading' => 'Container Unloading',
            'unloading' => 'Unloading',
            'ready_for_pickup' => 'Ready for Pickup',
            'planning' => 'Planning',
            'in_progress' => 'In Progress',
            'ready_for_departure' => 'Ready for Departure',
            'in_transit' => 'In Transit',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
        ]);
    }

    public static function invoiceStatus(?string $status): string
    {
        return self::fromMap($status, [
            'draft' => 'Draft',
            'issued' => 'Issued',
            'partially_paid' => 'Partially Paid',
            'unpaid' => 'Unpaid',
            'paid' => 'Paid',
            'overdue' => 'Overdue',
            'cancelled' => 'Cancelled',
        ]);
    }

    public static function paymentStatus(?string $status): string
    {
        return self::fromMap($status, [
            'success' => 'Success',
            'settlement' => 'Settlement',
            'pending' => 'Pending',
            'capture' => 'Pending',
            'authorize' => 'Pending',
            'deny' => 'Denied',
            'denied' => 'Denied',
            'cancel' => 'Cancelled',
            'cancelled' => 'Cancelled',
            'expire' => 'Expired',
            'expired' => 'Expired',
            'failure' => 'Failure',
            'failed' => 'Failure',
            'unpaid' => 'Unpaid',
            'refunded' => 'Refunded',
            'partial_refund' => 'Partial Refund',
            'chargeback' => 'Chargeback',
        ]);
    }

    public static function paymentMethod(?string $method): string
    {
        return self::fromMap($method, [
            'transfer' => 'Transfer',
            'giro' => 'Giro',
            'cash' => 'Cash',
            'virtual_account' => 'Virtual Account',
            'midtrans' => 'Midtrans',
            'manual' => 'Manual',
        ]);
    }

    public static function containerOwnership(?string $ownership): string
    {
        return self::fromMap($ownership, [
            'company' => 'Company',
            'vendor' => 'Vendor',
            'customer' => 'Customer',
        ]);
    }

    public static function containerStatus(?string $status): string
    {
        return self::fromMap($status, [
            'available' => 'Available',
            'reserved' => 'Reserved',
            'in_transit' => 'In Transit',
            'maintenance' => 'Maintenance',
            'inactive' => 'Inactive',
        ]);
    }

    public static function vendorInvoiceStatus(?string $status): string
    {
        return self::fromMap($status, [
            'received' => 'Received',
            'under_verification' => 'Under Verification',
            'ready_for_payment' => 'Ready for Payment',
            'paid' => 'Paid',
            'rejected' => 'Rejected',
        ]);
    }

    public static function vendorPaymentStatus(?string $status): string
    {
        return self::fromMap($status, [
            'waiting_approval' => 'Waiting Approval',
            'ready_to_pay' => 'Ready to Pay',
            'paid' => 'Paid',
            'cancelled' => 'Cancelled',
        ]);
    }

    private static function fromMap(?string $value, array $map): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        $key = strtolower(str_replace([' ', '-'], '_', trim($value)));

        return $map[$key] ?? self::humanize($key);
    }

    private static function humanize(string $key): string
    {
        return ucwords(str_replace('_', ' ', $key));
    }
}
