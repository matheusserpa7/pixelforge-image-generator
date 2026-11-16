<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers for every response (docs/specs/000-foundation/tech-spec.md).
 *
 * Scripts must carry the per-request nonce that @vite and the layout print,
 * so injected inline scripts cannot run. Styles allow 'unsafe-inline' because
 * Vue components set style attributes, which cannot carry a nonce.
 *
 * Horizon and Pulse ship inline scripts without nonces, so their admin-only
 * dashboards get every header except the CSP.
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();

        /** @var Response $response */
        $response = $next($request);

        if (! $this->isInternalDashboard($request)) {
            $response->headers->set('Content-Security-Policy', $this->contentSecurityPolicy($nonce));
        }

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        if (app()->isProduction()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    private function isInternalDashboard(Request $request): bool
    {
        $paths = [config()->string('horizon.path'), config()->string('pulse.path')];

        return collect($paths)->contains(fn (string $path): bool => $request->is($path, "{$path}/*"));
    }

    private function contentSecurityPolicy(string $nonce): string
    {
        // In development the Vite dev server serves scripts and hot reloading.
        $vite = Vite::isRunningHot() ? trim((string) file_get_contents(public_path('hot'))) : '';
        $viteSocket = str_replace(['http://', 'https://'], ['ws://', 'wss://'], $vite);

        $directives = [
            'default-src' => "'self'",
            'script-src' => "'self' 'nonce-{$nonce}' {$vite}",
            'style-src' => "'self' 'unsafe-inline' {$vite}",
            // Fonts are self-hosted by laravel-vite-plugin.
            'font-src' => "'self' data: {$vite}",
            // Generated images and user-supplied reference images live on other hosts.
            'img-src' => "'self' data: blob: https:",
            'connect-src' => "'self' {$vite} {$viteSocket}",
            'object-src' => "'none'",
            'base-uri' => "'self'",
            'form-action' => "'self'",
            'frame-ancestors' => "'none'",
        ];

        return collect($directives)
            ->map(fn (string $value, string $name): string => trim("{$name} {$value}"))
            ->implode('; ');
    }
}
