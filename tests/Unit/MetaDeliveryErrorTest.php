<?php

use App\Support\MetaDeliveryError;

test('код Meta читается оператору по-русски', function (string $reason, string $expected) {
    expect(MetaDeliveryError::explain($reason))->toBe($expected);
})->with([
    'номер без WhatsApp' => [
        'meta error 131026: Message undeliverable — Message Undeliverable.',
        'на этом номере нет рабочего WhatsApp — свяжитесь звонком и проверьте номер',
    ],
    'биллинг аккаунта' => [
        'meta error 131042: Business eligibility payment issue — Message failed to send because your WhatsApp Business account currency is not configured.',
        'не настроен биллинг WhatsApp Business — платные шаблоны не уходят ни к кому',
    ],
    'вне 24-часового окна' => [
        'meta error 131047: Re-engagement message — More than 24 hours have passed since the recipient last replied to the sender number.',
        'прошло больше 24 часов с последнего сообщения контакта — нужен был шаблон',
    ],
    'приостановленный шаблон' => [
        'meta error 132015: Template Paused — Template is paused due to low quality.',
        'шаблон приостановлен Meta из-за низкого качества',
    ],
]);

test('отказ без причины назван прямо, а не показан пустым', function (?string $reason) {
    expect(MetaDeliveryError::explain($reason))->toBe('Meta не назвала причину');
})->with([
    'причины нет' => [null],
    'причина из одних пробелов' => ['   '],
]);

test('незнакомая строка показывается как есть, а не подменяется догадкой', function (string $reason) {
    expect(MetaDeliveryError::explain($reason))->toBe($reason);
})->with([
    'без кода вовсе' => ['Meta rejected: invalid recipient'],
    'код, которого нет в справочнике' => ['meta error 199999: Something new — Something New.'],
]);

test('код не вычитывается из середины длинного числа', function () {
    // Причина 131042 несёт ссылку на биллинг, а в ней business_id из
    // шестнадцати цифр: поиск кода по всей строке нашёл бы там что угодно.
    $billing = 'meta error 131042: Business eligibility payment issue — Visit https://business.facebook.com/billing_hub/accounts/details/?business_id=1026024386580716&asset_id=1007931877979444 to resolve this issue.';

    // 131000 — подстрока казахского номера 77013100055.
    $phone = 'Meta rejected message to 77013100055';

    expect(MetaDeliveryError::explain($billing))
        ->toBe('не настроен биллинг WhatsApp Business — платные шаблоны не уходят ни к кому')
        ->and(MetaDeliveryError::explain($phone))->toBe($phone);
});

test('аккаунт-уровневый код распознаётся в любой форме причины', function (string $reason, int $expected) {
    expect(MetaDeliveryError::accountLevelCode($reason))->toBe($expected);
})->with([
    'биллинг в каноничной форме' => [
        'meta error 131042: Business eligibility payment issue — Message failed to send because your WhatsApp Business account currency is not configured.',
        131042,
    ],
    'качество номера в теле HTTP-ответа' => [
        'HTTP request returned status code 400: {"error":{"code":131048,"title":"Spam rate limit hit"}}',
        131048,
    ],
    'лимит категорий шаблонов' => [
        'meta error 131064: Messaging limit reached due to template category violations.',
        131064,
    ],
    'пропускная способность' => [
        'meta error 130429: Rate limit hit — Cloud API message throughput has been reached.',
        130429,
    ],
]);

test('не-аккаунтные и пустые причины не дают аккаунт-кода', function (?string $reason) {
    expect(MetaDeliveryError::accountLevelCode($reason))->toBeNull();
})->with([
    'причины нет' => [null],
    'код получателя, не аккаунта' => ['meta error 131026: Message undeliverable — Message Undeliverable.'],
    'аккаунт-код внутри длинного числа' => ['Meta rejected message to 77713042955'],
    'строка без кода' => ['Some transport error'],
]);

test('код находится и в ответе неудавшегося запроса отправки', function () {
    // Отправка, не принятая Dereu, кладёт в журнал сообщение HTTP-исключения
    // с телом ответа — без форматирования «meta error <код>».
    $reason = 'HTTP request returned status code 400: {"error":{"message":"Message undeliverable","code":131026}}';

    expect(MetaDeliveryError::explain($reason))
        ->toBe('на этом номере нет рабочего WhatsApp — свяжитесь звонком и проверьте номер');
});
