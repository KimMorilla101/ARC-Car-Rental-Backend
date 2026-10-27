<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

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
        // Matches the mobile app's minimum; 72 is bcrypt's input limit.
        Password::defaults(fn () => Password::min(8)->max(72));

        $this->configureResetPasswordLinks();
        $this->configureRateLimiting();
        $this->configureStaffPermissions();
    }

    /**
     * Branches, delivery zones and agreement drafts are day-to-day staff work. Payment accounts,
     * business settings and publishing the agreement change what renters pay and sign, so only
     * admins may do them.
     */
    private function configureStaffPermissions(): void
    {
        Gate::define('manage-reference-data', fn (User $user): bool => $user->canManageVehicles());
        Gate::define('manage-business-config', fn (User $user): bool => $user->isAdmin());
        // Reviewing documents and confirming, activating or cancelling bookings.
        Gate::define('manage-bookings', fn (User $user): bool => $user->canManageVehicles());
    }

    /**
     * Build emailed reset links from APP_URL, never from the request's Host header. Otherwise a
     * forged Host on a forgot-password request would email the renter a genuine token pointing at
     * an attacker's site ("password reset poisoning").
     */
    private function configureResetPasswordLinks(): void
    {
        ResetPassword::createUrlUsing(fn (User $user, string $token): string => rtrim(config('app.url'), '/').route('password.reset', [
            'token' => $token,
            'email' => $user->getEmailForPasswordReset(),
        ], absolute: false));
    }

    /**
     * Throttle the public authentication endpoints. Limits keyed by email and IP slow down guessing
     * against one account; the wider per-IP limit stops one client cycling through many emails.
     */
    private function configureRateLimiting(): void
    {
        $emailAndIp = fn (Request $request): string => Str::transliterate(
            Str::lower($request->string('email')->trim()).'|'.$request->ip()
        );

        RateLimiter::for('auth-login', fn (Request $request) => [
            Limit::perMinute(5)->by($emailAndIp($request)),
            Limit::perMinute(20)->by($request->ip()),
        ]);

        RateLimiter::for('auth-register', fn (Request $request) => [
            Limit::perMinute(5)->by($request->ip()),
            Limit::perHour(20)->by($request->ip()),
        ]);

        RateLimiter::for('password-reset', fn (Request $request) => [
            Limit::perMinute(3)->by($emailAndIp($request)),
            Limit::perMinute(10)->by($request->ip()),
        ]);

        // Signed-in booking actions, per account. The app re-quotes as the renter edits the form.
        RateLimiter::for('booking-quotes', fn (Request $request) => Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('booking-create', fn (Request $request) => Limit::perMinute(5)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('booking-uploads', fn (Request $request) => Limit::perMinute(20)->by($request->user()?->id ?: $request->ip()));
    }
}
