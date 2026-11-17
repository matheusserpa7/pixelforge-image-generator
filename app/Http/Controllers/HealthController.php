<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * GET /health: can this instance reach MySQL and Redis?
 * Used by the Docker healthcheck. Answers 200 when everything is up, 503 otherwise.
 */
final class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->passes('database', fn () => DB::select('select 1')),
            'redis' => $this->passes('redis', fn () => Redis::connection()->ping()),
        ];

        $healthy = ! in_array(false, $checks, true);

        return response()->json([
            'status' => $healthy ? 'ok' : 'fail',
        ], $healthy ? 200 : 503);
    }

    private function passes(string $name, callable $probe): bool
    {
        try {
            $probe();

            return true;
        } catch (Throwable $exception) {
            Log::error('Health check failed.', ['check' => $name, 'error' => $exception->getMessage()]);

            return false;
        }
    }
}
