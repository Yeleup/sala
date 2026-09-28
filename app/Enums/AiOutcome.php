<?php

namespace App\Enums;

/**
 * Result of handing a dialog step over to the AI assistant (see
 * docs/modules/ai-assistant.md). While InProgress the AI keeps the turn
 * and the scenario waits at the AI node. The other three release the
 * contact, and differ in who decided the block is over:
 *
 * Completed — the branch ran to its own end (the listing went to
 * moderation, the request was placed, a limit handed the contact to the
 * web form, they refused to go on). The contact leaves through the node's
 * "continue" output; with nothing wired there the dialog simply ends on
 * the branch's closing line.
 *
 * Menu — the contact asked for the menu (the «В меню» button, its typed
 * title, the worded request). They leave through the node's "menu" output
 * and, where it is not wired, start over from «Старт» — so the request is
 * answered whether or not "continue" leads anywhere.
 *
 * Back — one level up, to the menu whose option leads into the node; only
 * where no such menu exists it is answered the way Menu is.
 */
enum AiOutcome
{
    case InProgress;
    case Completed;
    case Menu;
    case Back;
}
