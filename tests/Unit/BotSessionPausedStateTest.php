<?php

use App\Models\BotSession;

/**
 * Валидный свежий снапшот — точка отсчёта; каждый негативный кейс ниже
 * портит или снимает ровно одно поле.
 */
function validPausedStateSnapshot(): array
{
    return [
        'node_id' => 'ask_master_name',
        'fingerprint' => 'a1b2c3',
        'state' => ['master_name' => 'Иван'],
        'saved_at' => now()->subHour()->toIso8601String(),
    ];
}

function pausedStateSnapshotWithout(string $field): array
{
    $snapshot = validPausedStateSnapshot();
    unset($snapshot[$field]);

    return $snapshot;
}

test('валидный свежий снапшот возвращается как массив', function () {
    $snapshot = validPausedStateSnapshot();
    $session = new BotSession(['paused_state' => $snapshot]);

    expect($session->pausedState())->toBe($snapshot);
});

test('пустая колонка даёт null', function () {
    $session = new BotSession(['paused_state' => null]);

    expect($session->pausedState())->toBeNull();
});

test('снапшот старше 48 часов даёт null', function () {
    $session = new BotSession(['paused_state' => array_merge(validPausedStateSnapshot(), [
        'saved_at' => now()->subHours(49)->toIso8601String(),
    ])]);

    expect($session->pausedState())->toBeNull();
});

test('снапшот ровно на границе 48 часов не считается свежим', function () {
    // «Строго моложе» — саму границу снапшот не переживает.
    $session = new BotSession(['paused_state' => array_merge(validPausedStateSnapshot(), [
        'saved_at' => now()->subHours(48)->toIso8601String(),
    ])]);

    expect($session->pausedState())->toBeNull();
});

test('битый или неполный снапшот даёт null', function (array $snapshot) {
    $session = new BotSession(['paused_state' => $snapshot]);

    expect($session->pausedState())->toBeNull();
})->with([
    'нет node_id' => [pausedStateSnapshotWithout('node_id')],
    'пустой node_id' => [array_merge(validPausedStateSnapshot(), ['node_id' => ''])],
    'node_id не строка' => [array_merge(validPausedStateSnapshot(), ['node_id' => 42])],
    'нет fingerprint' => [pausedStateSnapshotWithout('fingerprint')],
    'пустой fingerprint' => [array_merge(validPausedStateSnapshot(), ['fingerprint' => ''])],
    'нет state' => [pausedStateSnapshotWithout('state')],
    'state не массив' => [array_merge(validPausedStateSnapshot(), ['state' => 'строка'])],
    'нет saved_at' => [pausedStateSnapshotWithout('saved_at')],
    // Carbon::parse() молча читает пустую/пробельную строку как «сейчас»
    // вместо ошибки — без явной проверки на blank() снапшот прошёл бы как
    // самый свежий из возможных.
    'saved_at из пробелов' => [array_merge(validPausedStateSnapshot(), ['saved_at' => '   '])],
    'saved_at не парсится' => [array_merge(validPausedStateSnapshot(), ['saved_at' => 'совсем не дата'])],
]);
