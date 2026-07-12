<?php

namespace App\Providers;

use App\Listeners\RecordAiAttempts;
use App\Services\Ai\Audit\AiAuditState;
use App\Services\Ai\ScenarioAiAssistant;
use App\Services\Bot\AiAssistant;
use App\Services\DereuConnect;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AiAssistant::class, ScenarioAiAssistant::class);

        // Scoped, not singleton: the audit state is per request/job and
        // must never leak across Octane requests or queued jobs.
        $this->app->scoped(AiAuditState::class);

        $this->app->singleton(DereuConnect::class, function (): DereuConnect {
            return new DereuConnect(
                signingSecret: (string) config('services.dereu.connect.signing_secret'),
                keyPrefix: (string) config('services.dereu.connect.key_prefix'),
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
    }
}
