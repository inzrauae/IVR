<?php

namespace App\Providers;

use App\Models\User;
use App\Pbx\PbxManager;
use App\Support\Audit;
use App\Tenancy\Tenant;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(Tenant::class);
        $this->app->singleton(PbxManager::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Platform super admins pass every ability check. Tenant data is still isolated by the
        // organization scope, and they have no organization of their own.
        Gate::before(fn (User $user) => $user->isPlatformAdmin() ? true : null);

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));

        Event::listen(function (Login $event) {
            $event->user->forceFill(['last_login_at' => now()])->save();
            Audit::record('auth.login', $event->user);
        });
    }
}
