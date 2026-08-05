<?php

namespace App\Models;

use App\Enums\LocationStatus;
use App\Enums\LocationType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class CustomerLocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'code',
        'name',
        'type',
        'phone',
        'status',
        'country',
        'province',
        'city',
        'district',
        'postal_code',
        'address',
        'pic_name',
        'pic_email',
        'pic_mobile',
    ];

    protected function casts(): array
    {
        return [
            'type' => LocationType::class,
            'status' => LocationStatus::class,
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function userAccess(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_location_access');
    }

    public function activities(): MorphMany
    {
        return $this->morphMany(CompanyActivity::class, 'subject')->orderByDesc('occurred_at');
    }

    public function isActive(): bool
    {
        return $this->status === LocationStatus::Active;
    }

    public function isHeadOffice(): bool
    {
        return $this->type === LocationType::HeadOffice;
    }

    public function isOnlyHeadOffice(): bool
    {
        if (! $this->isHeadOffice()) {
            return false;
        }
        $count = static::where('company_id', $this->company_id)
            ->where('type', LocationType::HeadOffice)
            ->count();

        return $count === 1;
    }
}
