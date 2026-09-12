<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class HealthController extends Controller
{
    /**
     * Minimal liveness check: confirms the API process is up and responding.
     * Deliberately excludes dependency status — see status() for that.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => 'raffleguru-api',
            'version' => 'v1',
        ]);
    }

    /**
     * Readiness check: confirms the API's core dependencies (MySQL, Redis)
     * are reachable. Reports booleans only — never credentials or raw
     * exception details, so this stays safe to expose in local/dev.
     */
    public function status(): JsonResponse
    {
        $database = $this->check(fn () => DB::connection()->getPdo());
        $redis = $this->check(fn () => Redis::connection()->ping());

        $ok = $database && $redis;

        return response()->json([
            'status' => $ok ? 'ok' : 'degraded',
            'service' => 'raffleguru-api',
            'version' => 'v1',
            'dependencies' => [
                'database' => $database ? 'up' : 'down',
                'redis' => $redis ? 'up' : 'down',
            ],
        ], $ok ? 200 : 503);
    }

    private function check(callable $probe): bool
    {
        try {
            $probe();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
