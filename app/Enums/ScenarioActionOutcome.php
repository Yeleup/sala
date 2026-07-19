<?php

namespace App\Enums;

/**
 * Исход блока «Действие»: Done ведёт запуск по выходу «continue»,
 * Skipped — по выходу «skipped» (или тихо завершает запуск, если
 * выход не подключён). Провал (транспорт, БД) исходом не является —
 * это исключение, роняющее запуск в fail().
 */
enum ScenarioActionOutcome
{
    case Done;
    case Skipped;
}
