<?php

namespace App\Enums;

enum BotNodeType: string
{
    case Start = 'start';
    case Text = 'text';
    case ButtonMenu = 'buttons';
    case ListMenu = 'list';
    case AiInput = 'ai';

    /** Sends the personal signed CTA link into the supplier web portal. */
    case MyListings = 'my_listings';

    /**
     * A proactive WhatsApp message of a run-based scenario: adaptive
     * session/template channel, {{n}} variables, an output per quick
     * reply button, an optional reply timeout.
     */
    case Message = 'message';

    /** Branches yes/no on the current domain state (см. ScenarioCondition). */
    case Condition = 'condition';

    /** Performs a domain action (см. ScenarioAction). */
    case Action = 'action';

    /** Explicitly finishes the dialog or the run. */
    case End = 'end';

    /**
     * Blocks that stop the flow and wait for the contact's next message.
     * A Message block without buttons does not actually wait — the
     * engines check its options too.
     */
    public function waitsForInput(): bool
    {
        return match ($this) {
            self::ButtonMenu, self::ListMenu, self::AiInput, self::Message => true,
            self::Start, self::Text, self::MyListings, self::Condition, self::Action, self::End => false,
        };
    }
}
