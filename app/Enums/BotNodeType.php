<?php

namespace App\Enums;

enum BotNodeType: string
{
    case Start = 'start';
    case Text = 'text';
    case ButtonMenu = 'buttons';
    case ListMenu = 'list';
    case AiInput = 'ai';

    /**
     * Blocks that stop the flow and wait for the contact's next message.
     */
    public function waitsForInput(): bool
    {
        return match ($this) {
            self::ButtonMenu, self::ListMenu, self::AiInput => true,
            self::Start, self::Text => false,
        };
    }
}
