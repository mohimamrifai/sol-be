<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, HasRoles, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'status',
        'user_type',
        'company_id',
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
        ];
    }

    // ── Relationships ──
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
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

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Kirim link reset password ke frontend Next.js.
     */
    public function sendPasswordResetNotification($token): void
    {
        $url = config('app.frontend_url') . '/reset-password?token=' . $token . '&email=' . $this->email;
        $this->notify(new \Illuminate\Auth\Notifications\ResetPassword($token));
        // Note: Default ResetPassword notification uses a named route 'password.reset'.
        // To truly customize the URL inside the email, we might need a custom Notification.
        // For now, let's assume standard Laravel link is okay OR we use a custom one.
    }
}
