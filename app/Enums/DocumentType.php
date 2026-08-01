<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Document types surfaced to the customer portal "Documents" page.
 *
 * Each type is a virtual record aggregated from existing tables
 * (booking_attachments, shipment_tracking_photos, invoices, payments).
 * A new ID format is used: "{prefix}-{id}", e.g. "ba-12" (booking attachment),
 * "cn-34" (shipment consignment note), "inv-56" (invoice), etc.
 */
enum DocumentType: string
{
    case BookingAttachment = 'booking_attachment';
    case ConsignmentNote = 'consignment_note';
    case DeliveryOrder = 'delivery_order';
    case ProofOfDelivery = 'proof_of_delivery';
    case Invoice = 'invoice';
    case TaxInvoice = 'tax_invoice';
    case PaymentReceipt = 'payment_receipt';
    case OtherSupporting = 'other_supporting';

    public function prefix(): string
    {
        return match ($this) {
            self::BookingAttachment => 'ba',
            self::ConsignmentNote => 'cn',
            self::DeliveryOrder => 'do',
            self::ProofOfDelivery => 'pod',
            self::Invoice => 'inv',
            self::TaxInvoice => 'tinv',
            self::PaymentReceipt => 'rcp',
            self::OtherSupporting => 'oth',
        };
    }

    public function bucket(): string
    {
        return match ($this) {
            self::BookingAttachment,
            self::OtherSupporting => 'booking',
            self::ConsignmentNote,
            self::DeliveryOrder,
            self::ProofOfDelivery => 'shipment',
            self::Invoice,
            self::TaxInvoice,
            self::PaymentReceipt => 'billing',
        };
    }

    public static function fromPrefix(string $prefix): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->prefix() === $prefix) {
                return $case;
            }
        }
        return null;
    }

    public static function parseId(string $id): array
    {
        $parts = explode('-', $id, 2);
        if (count($parts) !== 2) {
            return [null, null];
        }
        return [self::fromPrefix($parts[0]), (int) $parts[1]];
    }
}
