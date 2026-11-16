<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request a `request_id` (docs/adr/0019-observability.md).
 *
 * Laravel's Context adds it to every log line and carries it into the queued
 * jobs this request dispatches. `user_id` is added when the user is resolved
 * (see AppServiceProvider). The id is echoed back as X-Request-Id.
 */
final class AssignRequestContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->requestId($request);

        Context::add('request_id', $requestId);

        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }

    /**
     * Reuse the id set by a proxy when it looks safe to log, otherwise make one.
     */
    private function requestId(Request $request): string
    {
        $incoming = (string) $request->headers->get('X-Request-Id', '');

        return preg_match('/^[A-Za-z0-9-]{8,64}$/', $incoming) === 1 ? $incoming : (string) Str::uuid();
    }
}
