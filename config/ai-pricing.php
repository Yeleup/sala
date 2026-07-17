<?php

/*
|--------------------------------------------------------------------------
| Тарифы AI-моделей (USD за 1 млн токенов)
|--------------------------------------------------------------------------
|
| Снимок тарифа сохраняется в ai_attempts на момент вызова, по нему
| считается estimated_cost_usd. Это ОЦЕНКА, а не биллинг: точное списание
| сверяйте с billing-выгрузкой провайдера. null = тариф неизвестен: такой
| вызов получает cost_status=unknown (никогда не «ноль»).
|
| Проверяйте актуальность цен при смене модели или прайс-листа провайдера.
*/

return [

    'models' => [

        // Дефолтная текстовая модель проекта — заполните актуальный тариф.
        'gpt-5.4' => ['input' => null, 'output' => null, 'cache_read' => null, 'cache_write' => null],

        // Дефолтная модель транскрибации — заполните актуальный тариф.
        'gpt-4o-transcribe-diarize' => ['input' => null, 'output' => null, 'cache_read' => null, 'cache_write' => null],

        // Модель эмбеддингов для векторного поиска: у неё нет output-токенов.
        'text-embedding-3-small' => ['input' => 0.02, 'output' => null, 'cache_read' => null, 'cache_write' => null],

        'gpt-4o' => ['input' => 2.50, 'output' => 10.00, 'cache_read' => 1.25, 'cache_write' => null],
        'gpt-4o-mini' => ['input' => 0.15, 'output' => 0.60, 'cache_read' => 0.075, 'cache_write' => null],
        'gpt-4.1' => ['input' => 2.00, 'output' => 8.00, 'cache_read' => 0.50, 'cache_write' => null],
        'gpt-4.1-mini' => ['input' => 0.40, 'output' => 1.60, 'cache_read' => 0.10, 'cache_write' => null],
        'gpt-4o-transcribe' => ['input' => 2.50, 'output' => 10.00, 'cache_read' => null, 'cache_write' => null],

    ],

];
