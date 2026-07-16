<?php

use App\Support\WhatsappText;

test('text within the limit is returned unchanged', function () {
    expect(WhatsappText::clamp('Автокран', 24))->toBe('Автокран');
});

test('text over the limit is truncated with an ellipsis that counts toward the limit', function () {
    $clamped = WhatsappText::clamp('Гидравлические экскаваторы-погрузчики', 24);

    expect(mb_strlen($clamped))->toBe(24)
        ->and($clamped)->toEndWith('…')
        ->and($clamped)->toBe('Гидравлические экскават…');
});

test('multibyte cyrillic text is measured in characters, not bytes', function () {
    $clamped = WhatsappText::clamp('ЁЁЁЁЁЁЁЁЁЁЁЁЁЁЁЁЁЁЁЁЁЁЁЁЁЁЁЁЁЁ', 10);

    expect(mb_strlen($clamped))->toBe(10)
        ->and($clamped)->toEndWith('…');
});

test('null text stays null', function () {
    expect(WhatsappText::clamp(null, 24))->toBeNull();
});
