<?php

use App\Exceptions\OutboundRequestBlocked;
use App\Services\DereuConnect;
use App\Support\DereuOutboundGuard;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.dereu', [
        'base_url' => 'https://api.dereu.example/api/v1',
        'platform_key' => 'plat_test.secret',
        'webhook_secret' => 'whsec_test',
        'external_id' => 'org_test',
        'connect' => [
            'url' => 'https://connect.dereu.example/connect',
            'signing_secret' => 'consec_test_secret',
        ],
    ]);
});

function outboundRequest(string $url): Request
{
    return new Request('POST', $url);
}

test('the guard blocks a call to the WhatsApp channel from a local environment', function (string $url) {
    app()->instance('env', 'local');

    expect(fn () => (new DereuOutboundGuard)(outboundRequest($url)))
        ->toThrow(OutboundRequestBlocked::class);
})->with([
    'messages' => 'https://api.dereu.example/api/v1/messages/send',
    'templates' => 'https://api.dereu.example/api/v1/companies/sala/templates',
    'hosted connect' => 'https://connect.dereu.example/connect?external_id=sala',
]);

test('the guard leaves everything but the WhatsApp channel alone', function () {
    app()->instance('env', 'local');

    $request = outboundRequest('https://api.openai.com/v1/chat/completions');

    expect((new DereuOutboundGuard)($request))->toBe($request);
});

test('the guard does not touch environments that are meant to send', function (string $environment) {
    app()->instance('env', $environment);

    $request = outboundRequest('https://api.dereu.example/api/v1/messages/send');

    expect((new DereuOutboundGuard)($request))->toBe($request);
})->with(['production', 'staging', 'testing']);

test('the block follows the configured host', function () {
    app()->instance('env', 'local');
    config()->set('services.dereu.base_url', 'https://api.dereu.other-example/api/v1');

    expect(fn () => (new DereuOutboundGuard)(outboundRequest('https://api.dereu.other-example/api/v1/messages/send')))
        ->toThrow(OutboundRequestBlocked::class);

    $request = outboundRequest('https://api.dereu.example/api/v1/messages/send');

    expect((new DereuOutboundGuard)($request))->toBe($request);
});

test('the block ignores the letter case a host is spelled with', function () {
    app()->instance('env', 'local');
    config()->set('services.dereu.base_url', 'https://API.Dereu.Example/api/v1');

    expect(fn () => (new DereuOutboundGuard)(outboundRequest('https://api.dereu.example/api/v1/messages/send')))
        ->toThrow(OutboundRequestBlocked::class);
});

test('a mock on this machine stays reachable, because that request never leaves it', function (string $baseUrl) {
    app()->instance('env', 'local');
    config()->set('services.dereu.base_url', $baseUrl.'/api/v1');

    $request = outboundRequest($baseUrl.'/api/v1/messages/send');

    expect((new DereuOutboundGuard)($request))->toBe($request);
})->with([
    'localhost' => 'http://localhost:8080',
    'loopback address' => 'http://127.0.0.1:8080',
    'a .test domain' => 'http://dereu-mock.test',
    'a docker service name' => 'http://dereu-mock:8080',
]);

test('the guard blocks everything when configuration leaves it unable to recognise the channel', function () {
    app()->instance('env', 'local');
    config()->set('services.dereu.base_url', '');
    config()->set('services.dereu.connect.url', '');

    expect(fn () => (new DereuOutboundGuard)(outboundRequest('https://api.openai.com/v1/chat/completions')))
        ->toThrow(OutboundRequestBlocked::class);
});

test('the guard is wired into the HTTP client itself, not into the Dereu callers', function () {
    app()->instance('env', 'local');
    Http::fake();

    expect(fn () => Http::get('https://api.dereu.example/api/v1/templates'))
        ->toThrow(OutboundRequestBlocked::class);

    Http::assertNothingSent();
});

test('the hosted connect redirect is refused too, though the HTTP client never sees it', function () {
    app()->instance('env', 'local');

    $connect = new DereuConnect(
        signingSecret: 'consec_test_secret',
        keyPrefix: 'ab12cd',
        connectUrl: 'https://connect.dereu.example/connect',
    );

    expect(fn () => $connect->connectUrl(
        externalId: 'org_test',
        returnUrl: 'https://app.example/admin/whatsapp/settings',
        nonce: 'nonce-value',
    ))->toThrow(OutboundRequestBlocked::class);
});
