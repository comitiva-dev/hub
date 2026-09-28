<?php

namespace App\Providers;

use App\Contract\Contract;
use App\Extension\AuditSink;
use App\Extension\BillingGateway;
use App\Extension\Community\AllowAllRuns;
use App\Extension\Community\NoAudit;
use App\Extension\Community\NoBilling;
use App\Extension\Community\PasswordIdentity;
use App\Extension\Community\StoredUsageMeter;
use App\Extension\Community\UnlimitedPlan;
use App\Extension\IdentityProvider;
use App\Extension\PlanLimits;
use App\Extension\RunGate;
use App\Extension\UsageMeter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * The hub's extension points with their community defaults (ADR 0015 in
 * comitiva). They and the domain events in App\Events are the hub's public
 * API: another package rebinds them in its own provider, registered after
 * this one (docs/extension-points.md).
 */
class HubServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        BillingGateway::class => NoBilling::class,
        UsageMeter::class => StoredUsageMeter::class,
        RunGate::class => AllowAllRuns::class,
        PlanLimits::class => UnlimitedPlan::class,
        IdentityProvider::class => PasswordIdentity::class,
        AuditSink::class => NoAudit::class,
    ];

    public function register(): void
    {
        $this->app->singleton(Contract::class, fn () => new Contract(Contract::directory()));
    }

    public function boot(): void
    {
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
    }
}
