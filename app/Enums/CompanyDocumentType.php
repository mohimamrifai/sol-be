<?php

declare(strict_types=1);

namespace App\Enums;

enum CompanyDocumentType: string
{
    case Npwp = 'npwp';
    case Nib = 'nib';
    case BusinessLicense = 'business_license';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Npwp => 'NPWP',
            self::Nib => 'NIB',
            self::BusinessLicense => 'Business License',
            self::Other => 'Other Documents',
        };
    }

    public function acceptedMimes(): array
    {
        return ['pdf', 'jpg', 'jpeg', 'png'];
    }

    public function maxSizeKb(): int
    {
        return 5120;
    }
}
