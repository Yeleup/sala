<?php

namespace App\Enums;

/**
 * What a message typed at a button/list menu step actually did in the
 * conversation — the navigator's first question, asked separately from
 * «where should this go» (see docs/modules/ai-assistant.md).
 *
 * The two used to be one field, and everything that named no section fell
 * into a single «не понял». A closing «спасибо», a refusal, a request for
 * a live person and genuine nonsense all came out the same, so the bot
 * answered all four the same way: it showed the menu again. Naming the
 * intent is what lets the bot stay quiet where quiet is the answer.
 */
enum MenuIntent: string
{
    /** The message names a section of the menu, or describes a task that has one. */
    case Navigate = 'navigate';

    /** It continues, or asks to come back to, the questionnaire left unfinished. */
    case Resume = 'resume';

    /** It asks about the service itself — the bot, the number, the terms. */
    case ServiceQuestion = 'service_question';

    /**
     * It closes a reply rather than opening anything: agreement, thanks,
     * an approving emoji. Nothing is asked and nothing is chosen.
     */
    case Acknowledgement = 'acknowledgement';

    /** It closes the conversation: nothing is needed right now. */
    case Decline = 'decline';

    /**
     * It asks for a live person — or says the bot is not understanding and
     * keeps going in circles, which asks for the same thing.
     */
    case HumanHandoff = 'human_handoff';

    /** It opens the conversation and says nothing else. */
    case Greeting = 'greeting';

    /** Anything else — the honest «не понял». */
    case Unclear = 'unclear';

    /** What this intent meant for the contact, in the operator's words — the chat's routing verdict. */
    public function label(): string
    {
        return match ($this) {
            self::Navigate => 'раздел меню',
            self::Resume => 'возврат к прерванной анкете',
            self::ServiceQuestion => 'вопрос о сервисе',
            self::Acknowledgement => 'подтверждение, бот промолчал',
            self::Decline => 'отказ, диалог закрыт',
            self::HumanHandoff => 'просьба о живом человеке',
            self::Greeting => 'приветствие',
            self::Unclear => 'не понял',
        };
    }

    /**
     * A missing or unrecognisable classification is read as «не понял»:
     * acting on a malformed answer would be worse than repeating the step,
     * which is what the caller does for this value anyway.
     */
    public static function fromExtraction(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Unclear) : self::Unclear;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
