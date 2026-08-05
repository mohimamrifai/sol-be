<?php

namespace App\Providers;

use App\Listeners\UpdateLastLoginAt;
use App\Models\Booking;
use App\Models\Shipment;
use App\Observers\BookingObserver;
use App\Observers\ShipmentObserver;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Booking::observe(BookingObserver::class);
        Shipment::observe(ShipmentObserver::class);

        Event::listen(Login::class, UpdateLastLoginAt::class);
    }
}
