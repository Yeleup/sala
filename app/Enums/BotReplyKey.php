<?php

namespace App\Enums;

/**
 * Встроенные ответы бота — тексты, которые бот отправляет сам, вне блоков
 * сценария (фолбэки транспорта и завершённых запусков). Значения без точек:
 * они же — имена полей формы Filament, точка дала бы вложенный statePath.
 */
enum BotReplyKey: string
{
    case StaleButton = 'stale_button';

    /**
     * Meta fails to deliver which button was pressed for some WhatsApp Web
     * devices migrated to LID identifiers (escalated to Meta, no ETA) — the
     * choice is unrecoverable, so the contact is asked to answer again.
     */
    case UnrecognizedPressMenu = 'unrecognized_press_menu';

    case UnrecognizedPressAi = 'unrecognized_press_ai';

    case UnrecognizedPressIdle = 'unrecognized_press_idle';

    case RunDecisionFinal = 'run_decision_final';

    /** Стандартный текст — используется, пока оператор не задал свой. */
    public function default(): string
    {
        return match ($this) {
            self::StaleButton => 'Эта кнопка из прежней версии бота и больше не действует.',
            self::UnrecognizedPressMenu => 'Не получилось распознать нажатие кнопки (такое бывает в WhatsApp Web). Ответьте цифрой:',
            self::UnrecognizedPressAi => 'Не получилось распознать нажатие кнопки — напишите, пожалуйста, текстом.',
            self::UnrecognizedPressIdle => 'Не получилось распознать нажатие кнопки. Если это была кнопка из уведомления о заявке или объявлении — ответьте, пожалуйста, с телефона.',
            self::RunDecisionFinal => 'Этот вопрос уже закрыт — ответ был зафиксирован ранее.',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::StaleButton => 'Кнопка из прежней версии бота',
            self::UnrecognizedPressMenu => 'Нажатие не распознано: шаг с кнопками',
            self::UnrecognizedPressAi => 'Нажатие не распознано: AI-шаг',
            self::UnrecognizedPressIdle => 'Нажатие не распознано: вне диалога',
            self::RunDecisionFinal => 'Вопрос уже закрыт',
        };
    }

    /** Когда отправляется — для подсказки формы и справки в редакторе. */
    public function description(): string
    {
        return match ($this) {
            self::StaleButton => 'Отправляется, когда контакт нажал кнопку, которой нет в текущей опубликованной версии сценария; после текста бот повторяет текущий шаг или начинает диалог заново.',
            self::UnrecognizedPressMenu => 'WhatsApp не смог передать, какая кнопка нажата (бывает в WhatsApp Web). Контакт стоит на шаге с кнопками или списком — после текста бот повторяет шаг с пронумерованными вариантами, ответить можно цифрой.',
            self::UnrecognizedPressAi => 'То же нераспознанное нажатие, но контакт находится на шаге AI-ассистента — бот просит ответить текстом.',
            self::UnrecognizedPressIdle => 'То же нераспознанное нажатие, когда активного диалога нет (например, нажата кнопка из уведомления о заявке или продлении — токен кнопки тоже потерян).',
            self::RunDecisionFinal => 'Отправляется при нажатии кнопки уже завершённого запуска — например, второй кнопки того же уведомления после принятого решения.',
        };
    }
}
