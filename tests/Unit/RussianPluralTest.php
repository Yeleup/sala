<?php

use App\Support\RussianPlural;

$forms = fn (int $count): string => RussianPlural::choose($count, 'объявление', 'объявления', 'объявлений');

test('единственное число берут только числа на 1, кроме подростковых', function () use ($forms) {
    expect($forms(1))->toBe('объявление')
        ->and($forms(21))->toBe('объявление')
        ->and($forms(101))->toBe('объявление')
        // 11 оканчивается на 1, но остаётся во множественном — ровно та
        // ловушка, ради которой правило и написано руками.
        ->and($forms(11))->toBe('объявлений')
        ->and($forms(111))->toBe('объявлений');
});

test('форма «2–4» берут числа на 2–4, кроме подростковых', function () use ($forms) {
    expect($forms(2))->toBe('объявления')
        ->and($forms(3))->toBe('объявления')
        ->and($forms(4))->toBe('объявления')
        ->and($forms(22))->toBe('объявления')
        ->and($forms(104))->toBe('объявления')
        ->and($forms(12))->toBe('объявлений')
        ->and($forms(13))->toBe('объявлений')
        ->and($forms(14))->toBe('объявлений');
});

test('остальные числа берут форму множественного', function () use ($forms) {
    expect($forms(0))->toBe('объявлений')
        ->and($forms(5))->toBe('объявлений')
        ->and($forms(9))->toBe('объявлений')
        ->and($forms(25))->toBe('объявлений')
        ->and($forms(100))->toBe('объявлений');
});

test('знак числа на форму не влияет', function () use ($forms) {
    expect($forms(-1))->toBe('объявление')
        ->and($forms(-3))->toBe('объявления')
        ->and($forms(-11))->toBe('объявлений');
});
