<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Services\Pixelforge\HttpPixelforgeClient;
use App\Services\Pixelforge\PixelforgeClient;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * Interfaces and the classes the container builds for them.
     * Tests replace the Pixelforge client with FakePixelforgeClient.
     *
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        PixelforgeClient::class => HttpPixelforgeClient::class,
    ];

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAuthorization();
        $this->configureRateLimiting();
    }

    /**
     * One Gate for every operator-only screen (docs/adr/0014-authorization-via-policy.md).
     */
    private function configureAuthorization(): void
    {
        Gate::define('admin', fn (User $user): bool => $user->is_admin);
    }

    /**
     * Named limiters used by route groups. Fortify defines its own `login` limiter.
     */
    private function configureRateLimiting(): void
    {
        // Generation requests per signed-in user (used by spec 004).
        RateLimiter::for('generations', fn (Request $request): Limit => Limit::perMinute(10)
            ->by((string) ($request->user()->id ?? $request->ip())));

        // Webhook deliveries per source IP. Pixelforge retries anything that is rejected.
        RateLimiter::for('webhooks', fn (Request $request): Limit => Limit::perMinute(300)
            ->by((string) $request->ip()));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    private function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
