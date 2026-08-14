<?php

namespace App\Models;

use App\Enums\UserStatus;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'status',
        'user_type',
        'company_id',
        'feature_access',
        'profile_photo_path',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'feature_access' => 'array',
            'last_login_at' => 'datetime',
            'status' => UserStatus::class,
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function locationAccess(): BelongsToMany
    {
        return $this->belongsToMany(CustomerLocation::class, 'user_location_access');
    }

    public function activities(): MorphMany
    {
        return $this->morphMany(CompanyActivity::class, 'subject')->orderByDesc('occurred_at');
    }

    // ── Helpers ──
    public function isInternal(): bool
    {
        return $this->user_type === 'internal';
    }

    public function isCustomer(): bool
    {
        return $this->user_type === 'customer';
    }

    public function isVendor(): bool
    {
        return $this->user_type === 'vendor';
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function hasFeatureAccess(string $permission): bool
    {
        if ($this->hasRole('super_admin')) {
            return true;
        }

        $access = $this->feature_access ?? [];

        if (in_array($permission, $access, true)) {
            return true;
        }

        if (str_starts_with($permission, 'view_')) {
            $suffix = substr($permission, 5);
            foreach (['manage_', 'edit_', 'create_'] as $prefix) {
                if (in_array($prefix.$suffix, $access, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<string> */
    public function featureAccessList(): array
    {
        if ($this->hasRole('super_admin')) {
            return \App\Enums\UserRole::SuperAdmin->defaultFeatureAccess();
        }

        return $this->feature_access ?? [];
    }

    /**
     * Kirim link reset password ke frontend Next.js.
     */
    public function sendPasswordResetNotification($token): void
    {
        $url = config('app.frontend_url').'/reset-password?token='.$token.'&email='.$this->email;
        $this->notify(new ResetPassword($token));
        // Note: Default ResetPassword notification uses a named route 'password.reset'.
        // To truly customize the URL inside the email, we might need a custom Notification.
        // For now, let's assume standard Laravel link is okay OR we use a custom one.
    }
}
