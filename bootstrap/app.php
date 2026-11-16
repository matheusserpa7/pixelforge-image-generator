<?php

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use App\Http\Middleware\AssignRequestContext;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        // Stateless routes: outside the `web` group, so no session and no CSRF.
        then: function (): void {
            Route::get('health', HealthController::class)->name('health');

            Route::middleware('throttle:webhooks')
                ->prefix('webhooks')
                ->name('webhooks.')
                ->group(base_path('routes/webhooks.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The app runs behind Docker networking and, in production, a reverse proxy
        // on the same host. Only private addresses may set X-Forwarded-* headers.
        $middleware->trustProxies(at: [
            '127.0.0.1',
            '10.0.0.0/8',
            '172.16.0.0/12',
            '192.168.0.0/16',
        ]);

        $middleware->append([
            AssignRequestContext::class,
            SecurityHeaders::class,
        ]);

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Reports to Sentry only when SENTRY_LARAVEL_DSN is set; a no-op otherwise.
        Integration::handles($exceptions);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
