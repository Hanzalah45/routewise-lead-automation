<?php

namespace App\Providers;

use App\Models\Lead;
use App\Models\User;
use App\Services\Crm\CrmClient;
use App\Services\Crm\CrmGateway;
use App\Services\Crm\CrmTransport;
use App\Services\Crm\HubSpotGateway;
use App\Services\Crm\PipedriveGateway;
use App\Services\Crm\HttpTransport;
use App\Services\Crm\InProcessMockTransport;
use App\Services\MockCrm\MockCrmApi;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CrmGateway::class, function ($app) {
            $crm = config('routewise.crm');

            return match ($crm['driver']) {
                'hubspot' => new HubSpotGateway((string) $crm['hubspot']['token'], pipeline: $crm['hubspot']['pipeline'], dealStage: $crm['hubspot']['deal_stage']),
                'pipedrive' => new PipedriveGateway((string) $crm['pipedrive']['token'], $crm['pipedrive']['base_url']),
                default => new CrmClient($app->make(CrmTransport::class)),
            };
        });

        // Real HTTP when ROUTEWISE_CRM_URL is set; otherwise the bundled Mock CRM is called in-process.
        $this->app->bind(CrmTransport::class, function ($app) {
            $url = config('routewise.crm.base_url');
            $token = (string) config('routewise.crm.token');

            return $url ? new HttpTransport($url, $token) : new InProcessMockTransport($app->make(MockCrmApi::class), $token);
        });
    }

    public function boot(): void
    {
        Paginator::useBootstrapFive();

        Gate::define('manage', fn (User $user) => $user->isAdmin());

        View::composer('layouts.app', fn ($view) => $view->with('navAttention', Lead::whereIn('status', [Lead::STATUS_DEAD, Lead::STATUS_RETRYING, Lead::STATUS_HELD])->count()));

        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute((int) config('routewise.webhook_rate_limit_per_minute'))
            ->by(($request->route('source') ?? 'unknown').'|'.$request->ip()));
    }
}
