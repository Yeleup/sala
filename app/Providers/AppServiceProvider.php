<?php

namespace App\Providers;

use App\Listeners\RecordAiAttempts;
use App\Services\Ai\AiMenuRouter;
use App\Services\Ai\Audit\AiAuditState;
use App\Services\Ai\ScenarioAiAssistant;
use App\Services\Bot\AiAssistant;
use App\Services\Bot\MenuRouter;
use App\Services\DereuConnect;
use App\Support\DereuOutboundGuard;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AiAssistant::class, ScenarioAiAssistant::class);
        $this->app->bind(MenuRouter::class, AiMenuRouter::class);

        // Scoped, not singleton: the audit state is per request/job and
        // must never leak across Octane requests or queued jobs.
        $this->app->scoped(AiAuditState::class);

        $this->app->singleton(DereuConnect::class, function (): DereuConnect {
            return new DereuConnect(
                signingSecret: (string) config('services.dereu.connect.signing_secret'),
                keyPrefix: DereuConnect::keyPrefixFromPlatformKey(config('services.dereu.platform_key')),
                connectUrl: (string) config('services.dereu.connect.url'),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::subscribe(RecordAiAttempts::class);

        // The block lives on the client, not on the callers — see
        // DereuOutboundGuard. Registered unconditionally: the guard itself
        // decides, so there is one place holding the rule.
        Http::globalRequestMiddleware(new DereuOutboundGuard);
    }
}
