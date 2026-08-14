<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vendor extends Model
{
    use HasFactory, SoftDeletes;

    public const TYPE_TRUCKING = 'trucking';

    public const TYPE_RAIL = 'rail_operator';

    public const TYPE_CONTAINER = 'container_provider';

    protected $fillable = [
        'name', 'code', 'business_entity', 'vendor_types', 'vendor_category',
        'npwp', 'address', 'country', 'province', 'city', 'district', 'postal_code',
        'phone', 'email', 'website', 'remark', 'contact_person', 'is_active',
        'payment_terms', 'payment_method', 'bank_name', 'bank_account_number',
        'account_holder', 'tax_status',
    ];

    protected function casts(): array
    {
        return [
            'vendor_types' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function vendorServices(): HasMany
    {
        return $this->hasMany(VendorService::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(VendorContact::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function isUsedInTransactions(): bool
    {
        if (Shipment::query()->where('pickup_vendor_id', $this->id)
            ->orWhere('delivery_vendor_id', $this->id)
            ->orWhere('rail_vendor_id', $this->id)->exists()) {
            return true;
        }

        if (\App\Models\VendorJobOrder::query()->where('vendor_id', $this->id)->exists()) {
            return true;
        }

        return ContainerAsset::query()->where('vendor_id', $this->id)->exists();
    }
}
