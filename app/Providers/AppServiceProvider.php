<?php

namespace App\Providers;

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
        \App\Models\Booking::observe(\App\Observers\BookingObserver::class);
        \App\Models\Shipment::observe(\App\Observers\ShipmentObserver::class);
    }
}
