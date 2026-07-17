<?php

use App\Services\DereuConnect;
use Illuminate\Support\Carbon;

afterEach(function () {
    Carbon::setTestNow();
});

function dereuConnectService(): DereuConnect
{
    return new DereuConnect(
        signingSecret: 'consec_test_secret',
        keyPrefix: 'plat_ab12cd',
        connectUrl: 'https://connect.dereu.test/connect',
    );
}

test('connectUrl builds a payload the connect page can verify', function () {
    Carbon::setTestNow('2026-07-09 12:00:00');

    $url = dereuConnectService()->connectUrl(
        externalId: 'org_123',
        returnUrl: 'https://app.partner.test/admin/whatsapp',
        nonce: 'nonce-value',
        ttlSeconds: 600,
        companyName: 'ООО Ромашка',
    );

    [$base, $query] = explode('?', $url, 2);
    parse_str($query, $params);

    expect($base)->toBe('https://connect.dereu.test/connect')
        ->and($params['p'])->toBe('plat_ab12cd')
        ->and($params['sig'])->toBe(DereuConnect::sign($params['d'], 'consec_test_secret'));

    $payload = json_decode((string) DereuConnect::base64UrlDecode($params['d']), true);

    expect($payload)->toBe([
        'external_id' => 'org_123',
        'return_url' => 'https://app.partner.test/admin/whatsapp',
        'nonce' => 'nonce-value',
        'exp' => Carbon::now()->addSeconds(600)->getTimestamp(),
        'company_name' => 'ООО Ромашка',
    ]);
});

test('connectUrl omits the company name when it is not given', function () {
    $url = dereuConnectService()->connectUrl('org_123', 'https://app.partner.test/done', 'nonce-value');

    parse_str(explode('?', $url, 2)[1], $params);
    $payload = json_decode((string) DereuConnect::base64UrlDecode($params['d']), true);

    expect($payload)->not->toHaveKey('company_name');
});

test('connectUrl adds account_mode to the payload when coexistence is requested', function () {
    $url = dereuConnectService()->connectUrl(
        externalId: 'org_123',
        returnUrl: 'https://app.partner.test/done',
        nonce: 'nonce-value',
        accountMode: 'coexistence',
    );

    parse_str(explode('?', $url, 2)[1], $params);
    $payload = json_decode((string) DereuConnect::base64UrlDecode($params['d']), true);

    expect($payload)->toHaveKey('account_mode', 'coexistence')
        ->and($params['sig'])->toBe(DereuConnect::sign($params['d'], 'consec_test_secret'));
});

test('connectUrl omits account_mode by default, defaulting Dereu to business_only', function () {
    $url = dereuConnectService()->connectUrl('org_123', 'https://app.partner.test/done', 'nonce-value');

    parse_str(explode('?', $url, 2)[1], $params);
    $payload = json_decode((string) DereuConnect::base64UrlDecode($params['d']), true);

    expect($payload)->not->toHaveKey('account_mode');
});

test('connectUrl signs the base64url string itself, not its JSON', function () {
    // Регресс-защита от главной ошибки схемы: подписываться должна строка d.
    $url = dereuConnectService()->connectUrl('org_123', 'https://app.partner.test/done', 'nonce-value');

    parse_str(explode('?', $url, 2)[1], $params);

    $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $params['d'], 'consec_test_secret', true)), '+/', '-_'), '=');

    expect($params['sig'])->toBe($expected);
});

test('verifyResult decodes a correctly signed OUT redirect', function () {
    $data = [
        'dereu_company_id' => 'co_abc123',
        'phone_number_id' => '1234567890',
        'waba_id' => '9876543210',
        'status' => 'connected',
        'nonce' => 'nonce-value',
    ];

    $result = DereuConnect::base64UrlEncode((string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $signature = DereuConnect::sign($result, 'consec_test_secret');

    expect(dereuConnectService()->verifyResult($result, $signature))->toBe($data);
});

test('verifyResult rejects a wrong signature', function () {
    $result = DereuConnect::base64UrlEncode('{"dereu_company_id":"co_1"}');

    expect(dereuConnectService()->verifyResult($result, 'forged'))->toBeNull();
});

test('verifyResult rejects a tampered result even with a previously valid signature', function () {
    $original = DereuConnect::base64UrlEncode('{"status":"connected"}');
    $signature = DereuConnect::sign($original, 'consec_test_secret');
    $tampered = DereuConnect::base64UrlEncode('{"status":"failed"}');

    expect(dereuConnectService()->verifyResult($tampered, $signature))->toBeNull();
});

test('verifyResult rejects payloads that are not valid base64url json objects', function (string $raw) {
    $result = str_contains($raw, '!') ? $raw : DereuConnect::base64UrlEncode($raw);
    $signature = DereuConnect::sign($result, 'consec_test_secret');

    expect(dereuConnectService()->verifyResult($result, $signature))->toBeNull();
})->with([
    'invalid base64url' => '!!!',
    'not json' => 'plain text',
    'json but not an object' => '"connected"',
]);

test('verifyResult rejects results with missing or non-string fields', function (array $data) {
    $result = DereuConnect::base64UrlEncode((string) json_encode($data));
    $signature = DereuConnect::sign($result, 'consec_test_secret');

    expect(dereuConnectService()->verifyResult($result, $signature))->toBeNull();
})->with([
    'missing nonce' => [['dereu_company_id' => 'co_1', 'phone_number_id' => '1', 'waba_id' => '2', 'status' => 'connected']],
    'non-string field' => [['dereu_company_id' => 'co_1', 'phone_number_id' => 1, 'waba_id' => '2', 'status' => 'connected', 'nonce' => 'n']],
]);

test('an unconfigured service reports itself and refuses to sign', function () {
    $connect = new DereuConnect(signingSecret: '', keyPrefix: '', connectUrl: 'https://connect.dereu.test/connect');

    expect($connect->isConfigured())->toBeFalse()
        ->and(fn () => $connect->connectUrl('org_123', 'https://app.partner.test/done', 'nonce-value'))
        ->toThrow(RuntimeException::class);
});
