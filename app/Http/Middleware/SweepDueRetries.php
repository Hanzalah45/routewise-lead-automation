<?php

namespace App\Http\Middleware;

use App\Services\Crm\CrmSyncService;
use App\Services\SchedulerHealth;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Safety net: after a response has been sent, process a few overdue CRM retries (at most once every 20 seconds).
 * Retries therefore still happen while people use the app or leads keep arriving, even if no scheduler is running.
 * The scheduler / worker remains the reliable path when the app is idle.
 */
class SweepDueRetries
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if ($request->is('up') || ! Cache::add('routewise:sweep-lock', 1, 20)) {
            return;
        }

        try {
            app(CrmSyncService::class)->runDue(null, 5);
            SchedulerHealth::beat();
        } catch (\Throwable $e) {
            report($e);   // never let a sweep problem affect the request that triggered it
        }
    }
}
