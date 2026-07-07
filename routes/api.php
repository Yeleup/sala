<?php

use App\Http\Controllers\DereuWebhookController;
use App\Http\Middleware\VerifyDereuSignature;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/dereu', DereuWebhookController::class)
    ->middleware(VerifyDereuSignature::class)
    ->withoutMiddleware('throttle:api')
    ->name('webhooks.dereu');
