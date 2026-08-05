<?php

declare(strict_types=1);

namespace App\Listeners;

use Illuminate\Auth\Events\Login;
use Illuminate\Database\Eloquent\Model;

class UpdateLastLoginAt
{
    public function handle(Login $event): void
    {
        $user = $event->user;
        if ($user instanceof Model) {
            $user->forceFill(['last_login_at' => now()])->save();
        }
    }
}
