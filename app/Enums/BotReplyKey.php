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
     * choice is unrecoverable, so the bot only explains and takes no
     * further action.
     */
    case UnrecognizedPress = 'unrecognized_press';

    case RunDecisionFinal = 'run_decision_final';

    /** Стандартный текст — используется, пока оператор не задал свой. */
    public function default(): string
    {
        return match ($this) {
            self::StaleButton => 'Эта кнопка из прежней версии бота и больше не действует.',
            self::UnrecognizedPress => 'Не получилось распознать нажатие кнопки. Если это была кнопка из уведомления о заявке или объявлении — ответьте, пожалуйста, с телефона.',
            self::RunDecisionFinal => 'Этот вопрос уже закрыт — ответ был зафиксирован ранее.',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::StaleButton => 'Кнопка из прежней версии бота',
            self::UnrecognizedPress => 'Нажатие кнопки не распознано',
            self::RunDecisionFinal => 'Вопрос уже закрыт',
        };
    }

    /** Когда отправляется — для подсказки формы и справки в редакторе. */
    public function description(): string
    {
        return match ($this) {
            self::StaleButton => 'Отправляется, когда контакт нажал кнопку, которой нет в текущей опубликованной версии сценария; после текста бот повторяет текущий шаг или начинает диалог заново.',
            self::UnrecognizedPress => 'WhatsApp не смог передать, какая кнопка нажата (бывает в WhatsApp Web). Бот отправляет только это пояснение и больше ничего не делает: активный диалог остаётся на текущем шаге, новый не начинается. Кнопки уведомлений о заявке или объявлении при таком сбое восстановить нельзя — стандартный текст советует ответить с телефона.',
            self::RunDecisionFinal => 'Отправляется при нажатии кнопки уже завершённого запуска — например, второй кнопки того же уведомления после принятого решения.',
        };
    }
}
