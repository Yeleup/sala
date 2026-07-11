<?php

use Illuminate\Support\Facades\Route;

test('asset URLs use HTTPS when the request is forwarded through a secure proxy', function (): void {
    Route::get('/_test/proxy-asset-url', fn () => asset('css/filament/filament/app.css'));

    $this
        ->withHeaders([
            'X-Forwarded-Host' => 'example.ngrok-free.app',
            'X-Forwarded-Port' => '443',
            'X-Forwarded-Proto' => 'https',
        ])
        ->get('http://localhost/_test/proxy-asset-url')
        ->assertSuccessful()
        ->assertContent('https://example.ngrok-free.app/css/filament/filament/app.css');
});
