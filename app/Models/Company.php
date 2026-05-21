<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Company extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'business_entity_type',
        'company_code',
        'npwp',
        'nib',
        'address',
        'city',
        'province',
        'postal_code',
        'contact_person',
        'email',
        'phone',
        'status',
        'billing_cycle',
        'payment_type',
        'postpaid_term_days',
    ];

    protected static function booted(): void
    {
        static::creating(function (Company $company) {
            $company->name = self::normalizeName($company->name);

            if (! $company->company_code) {
                $company->company_code = self::generateUniqueCompanyCode($company->name);
            }
        });
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function customerDiscounts(): HasMany
    {
        return $this->hasMany(CustomerDiscount::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function hasOverdueInvoices(): bool
    {
        return $this->invoices()->where('status', 'overdue')->exists();
    }

    public static function normalizeName(string $name): string
    {
        $name = trim($name);
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;

        return $name;
    }

    public static function generateUniqueCompanyCode(string $companyName): string
    {
        $candidates = self::generateCompanyCodeCandidates($companyName);

        foreach ($candidates as $candidate) {
            if (! self::withTrashed()->where('company_code', $candidate)->exists()) {
                return $candidate;
            }
        }

        for ($i = 0; $i < 50; $i++) {
            $candidate = strtoupper(Str::random(3));
            if (! self::withTrashed()->where('company_code', $candidate)->exists()) {
                return $candidate;
            }
        }

        return strtoupper(Str::random(3));
    }

    public static function generateCompanyCodeCandidates(string $companyName): array
    {
        $name = Str::upper(Str::ascii($companyName));
        $name = preg_replace('/[^A-Z0-9\s\-_]/', ' ', $name) ?? $name;
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);

        $tokens = preg_split('/[\s\-_]+/', $name) ?: [];
        $tokens = array_values(array_filter($tokens, fn ($t) => $t !== ''));

        $lettersOnly = preg_replace('/[^A-Z]/', '', $name) ?? '';

        $candidates = [];

        $initials = '';
        foreach ($tokens as $t) {
            $first = preg_replace('/[^A-Z]/', '', substr($t, 0, 1)) ?? '';
            if ($first !== '') {
                $initials .= $first;
            }
            if (strlen($initials) >= 3) {
                break;
            }
        }

        if (strlen($initials) < 3) {
            $fillSource = preg_replace('/[^A-Z]/', '', implode('', $tokens)) ?? '';
            $fillIndex = 0;
            while (strlen($initials) < 3 && $fillIndex < strlen($fillSource)) {
                $ch = $fillSource[$fillIndex];
                if ($ch !== '') {
                    $initials .= $ch;
                }
                $fillIndex++;
            }
        }

        if (strlen($initials) >= 3) {
            $candidates[] = substr($initials, 0, 3);
        }

        if (strlen($lettersOnly) >= 3) {
            $candidates[] = substr($lettersOnly, 0, 3);
        }

        if (strlen($lettersOnly) > 3) {
            for ($i = 1; $i + 2 < strlen($lettersOnly); $i++) {
                $candidates[] = substr($lettersOnly, $i, 3);
            }
        }

        $candidates = array_values(array_unique(array_filter($candidates, fn ($c) => strlen($c) === 3)));

        if (count($candidates) === 0) {
            $candidates[] = 'COM';
        }

        return $candidates;
    }
}
