<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
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
        // The Pulse dashboard exposes operational information and must remain
        // available only to users who can access the existing admin area.
        Gate::define('viewPulse', function (User $user): bool {
            return (bool) $user->is_admin;
        });
    }
}
