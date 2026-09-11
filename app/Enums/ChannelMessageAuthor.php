<?php

namespace App\Enums;

/**
 * Who wrote a journal entry. Direction alone stopped being enough once the
 * operator's own messages joined the journal: they leave the same number
 * as the bot's and so are outbound too, but nothing about them is the
 * bot's doing — no delivery statuses of ours, no cost of ours, and no
 * reason to count them when measuring whether the bot is being delivered.
 */
enum ChannelMessageAuthor: string
{
    case Contact = 'contact';

    case Bot = 'bot';

    /** Sent by a person from the WhatsApp Business app on the same number. */
    case Operator = 'operator';

    public function label(): string
    {
        return match ($this) {
            self::Contact => 'Контакт',
            self::Bot => 'Бот',
            self::Operator => 'Оператор',
        };
    }
}
