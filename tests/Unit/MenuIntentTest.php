<?php

use App\Enums\MenuIntent;

test('у каждого намерения есть подпись для чата оператора', function (MenuIntent $intent) {
    // Подпись строится match'ем по enum: новое намерение без подписи —
    // это упавшая страница чата, и падает она на продовых данных, а не
    // здесь. Пусть падает здесь.
    expect($intent->label())->not->toBe('');
})->with(MenuIntent::cases());

test('непригодный ответ модели читается как «не понял»', function (mixed $value) {
    // Действовать по мусору хуже, чем повторить шаг: «не понял» и есть
    // повтор шага.
    expect(MenuIntent::fromExtraction($value))->toBe(MenuIntent::Unclear);
})->with([
    'нет поля' => [null],
    'незнакомое значение' => ['handoff'],
    'пустая строка' => [''],
    'число' => [7],
    'массив' => [['navigate']],
    'булево' => [true],
]);

test('намерение читается из строки, которую отдаёт модель', function () {
    expect(MenuIntent::fromExtraction('human_handoff'))->toBe(MenuIntent::HumanHandoff);
});

test('перечень значений для схемы совпадает с перечнем вариантов', function () {
    expect(MenuIntent::values())->toBe(array_column(MenuIntent::cases(), 'value'));
});
