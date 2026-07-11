<?php

namespace App\Providers;

use App\Services\DereuConnect;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
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
        //
    }
}
