<?php

use App\Models\BotSession;

/**
 * menuStreak() читает колонку так же осторожно, как pausedState(): всё,
 * что не похоже на серию непонятых сообщений этого шага, читается как
 * «серии нет». Битая запись не должна ни ронять ход, ни глушить бота на
 * шаге, который он на самом деле проходит впервые.
 */
function streakSession(mixed $streak): BotSession
{
    $session = new BotSession;
    $session->menu_streak = $streak;

    return $session;
}

test('серия читается только для того шага, на котором её записали', function () {
    $session = streakSession(['node_id' => 'menu', 'count' => 2, 'texts' => ['Мотор']]);

    expect($session->menuStreak('menu'))->toBe(['count' => 2, 'texts' => ['Мотор']])
        ->and($session->menuStreak('другой_шаг'))->toBeNull()
        ->and($session->menuStreak(null))->toBeNull();
});

test('всё, что не похоже на серию, читается как её отсутствие', function (mixed $streak) {
    expect(streakSession($streak)->menuStreak('menu'))->toBeNull();
})->with([
    'колонка пуста' => [null],
    'не массив' => ['мусор'],
    'нет узла' => [['count' => 2, 'texts' => []]],
    'нет счётчика' => [['node_id' => 'menu', 'texts' => []]],
    'счётчик не число' => [['node_id' => 'menu', 'count' => 'два', 'texts' => []]],
    'счётчик нулевой' => [['node_id' => 'menu', 'count' => 0, 'texts' => []]],
    'тексты не массив' => [['node_id' => 'menu', 'count' => 2, 'texts' => 'Мотор']],
]);

test('нетекстовые элементы из накопленного отбрасываются, а список переиндексируется', function () {
    $session = streakSession(['node_id' => 'menu', 'count' => 3, 'texts' => ['Мотор', 42, null, 'Ходовка']]);

    expect($session->menuStreak('menu')['texts'])->toBe(['Мотор', 'Ходовка']);
});
