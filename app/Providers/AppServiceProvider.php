<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Services\Pixelforge\HttpPixelforgeClient;
use App\Services\Pixelforge\PixelforgeClient;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PixelforgeClient::class, function (): HttpPixelforgeClient {
            $timeoutConfig = config('services.pixelforge.timeout', 10);
            $timeout = is_numeric($timeoutConfig) ? (int) $timeoutConfig : 10;

            if ($timeout < 1) {
                $timeout = 10;
            }

            $apiKey = config('services.pixelforge.api_key');
            $baseUrl = config('services.pixelforge.base_url', 'https://pixelforge.promptedit.dev/v1');

            return new HttpPixelforgeClient(
                is_string($baseUrl) ? $baseUrl : 'https://pixelforge.promptedit.dev/v1',
                is_string($apiKey) ? $apiKey : null,
                $timeout,
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAuthorization();
        $this->configureRateLimiting();
        $this->configureObservability();
    }

    /**
     * One Gate for every operator-only screen (docs/adr/0014-authorization-via-policy.md).
     * Pulse asks for `viewPulse`; Horizon's gate lives in HorizonServiceProvider.
     */
    private function configureAuthorization(): void
    {
        Gate::define('admin', fn (User $user): bool => $user->is_admin);
        Gate::define('viewPulse', fn (User $user): bool => $user->is_admin);
    }

    /**
     * Correlated logs (docs/adr/0019-observability.md). AssignRequestContext adds
     * `request_id`; the signed-in user is added as soon as it is resolved.
     */
    private function configureObservability(): void
    {
        Event::listen(function (Authenticated $event): void {
            Context::add('user_id', $event->user->getAuthIdentifier());
        });

        // The job's Context (request_id, user_id, ...) is attached automatically.
        Queue::failing(function (JobFailed $event): void {
            Log::error('Queued job failed.', [
                'job' => $event->job->resolveName(),
                'queue' => $event->job->getQueue(),
                'attempts' => $event->job->attempts(),
                'error' => $event->exception->getMessage(),
            ]);
        });
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
