<?php

namespace App\Services;

use App\Enums\ChannelDirection;
use App\Models\BotSession;
use App\Models\ChannelMessage;
use App\Models\Contact;
use Illuminate\Support\Facades\Log;

/**
 * The rule for when the bot steps aside because a person is handling the
 * conversation, and when it comes back.
 *
 * Kept out of the job and out of the engine because the whole difficulty
 * is in the condition, not in the effect: silencing the bot on *any*
 * message from the operator's phone would be worse than the problem it
 * fixes. The operator's own outreach goes to dozens of numbers at a time
 * and ends with «напишите „РЕМОНТ“» — and the bot is what answers that.
 * Pausing after a broadcast would mean everyone who did reply got nothing
 * at all.
 *
 * So the pause turns on only where the operator is *joining* a
 * conversation rather than *opening* one: the contact wrote to us within
 * the last day. On the 141 echoes recorded before this existed, that
 * condition holds for 35 of them across 18 contacts — every live exchange,
 * including the 27 August one, and none of the cold outreach.
 */
class OperatorHandoff
{
    /**
     * How long the bot stays out after the operator's last message.
     *
     * Short on purpose. The mistake this can make — pausing a conversation
     * nobody is actually handling — leaves a person without an answer, so
     * it has to expire on its own rather than wait to be noticed. The
     * 27 August exchange lasted 22 minutes; an hour covers that kind of
     * conversation with room to spare, and every further message from the
     * operator pushes it out again.
     */
    public const int HANDOFF_MINUTES = 60;

    /** How recently the contact must have written for an echo to read as joining rather than opening. */
    private const int LIVE_CONVERSATION_HOURS = 24;

    public function isActive(Contact $contact): bool
    {
        return $contact->operator_handoff_until?->isFuture() ?? false;
    }

    /**
     * Turn the pause on — or push it further out — if this message from
     * the operator landed in a conversation that was already going.
     */
    public function considerEcho(ChannelMessage $entry): void
    {
        $contact = $entry->contact;

        if ($contact === null || ! $this->joinsLiveConversation($entry, $contact)) {
            return;
        }

        $this->start($contact);
    }

    public function start(Contact $contact): void
    {
        $wasActive = $this->isActive($contact);

        $contact->forceFill(['operator_handoff_until' => now()->addMinutes(self::HANDOFF_MINUTES)])->save();

        if (! $wasActive) {
            Log::info('The bot stepped aside: an operator is handling this conversation.', [
                'contact_id' => $contact->id,
            ]);
        }
    }

    /**
     * Give the conversation back to the bot before the hour is out — the
     * «Вернуть бота» button in the chat.
     */
    public function end(Contact $contact): void
    {
        if (! $this->isActive($contact)) {
            return;
        }

        $this->release($contact);
    }

    /**
     * Give the conversation back because the contact answered the bot
     * itself: they pressed a button it had put into the chat. Only the bot
     * can put buttons there — the operator writes from the WhatsApp app
     * and has none — so a press is never the start of a conversation with
     * a person, and silence in reply is the one answer that cannot be
     * right. The contact 369 incident: three presses of «Ремонт
     * спецтехники» in eight minutes, all swallowed, with live buttons
     * still on screen.
     *
     * The pause ends rather than merely letting this one press through:
     * the very next step may ask for words, and a bot that answers the
     * buttons and goes silent on the answer is the same dead end one step
     * later. The operator takes the conversation back by writing again —
     * their next message re-arms the pause at once.
     *
     * The dialog is deliberately kept: the press is an answer to the step
     * the contact is standing on, and closing it would answer «Что вас
     * интересует?» with «Что вас интересует?» — the contact 247 incident.
     */
    public function releaseForPress(Contact $contact): void
    {
        if ($contact->operator_handoff_until === null) {
            return;
        }

        $this->release($contact, keepDialog: true);
    }

    /**
     * Give the conversation back because the hour ran out. Called on the
     * contact's next message rather than by a clock: nothing needs to
     * happen until they write again, and that is the moment the bot has
     * to decide what it is answering.
     */
    public function releaseExpired(Contact $contact): void
    {
        if ($contact->operator_handoff_until === null || $this->isActive($contact)) {
            return;
        }

        $this->release($contact);
    }

    /**
     * The dialog is closed along with the pause, however it ended: the bot
     * would otherwise come back standing on «Что вас интересует?», a
     * question the person answered to a human long ago.
     *
     * $keepDialog is the one exception — the pause is being lifted by an
     * answer to that very question (see releaseForPress), so the step is
     * still live and closing it would throw the answer away.
     */
    private function release(Contact $contact, bool $keepDialog = false): void
    {
        $contact->forceFill(['operator_handoff_until' => null])->save();

        if (! $keepDialog) {
            BotSession::query()
                ->where('contact_id', $contact->id)
                ->update([
                    'current_node_id' => null,
                    'current_node_fingerprint' => null,
                    'menu_streak' => null,
                    'last_dialog_ended_at' => now(),
                ]);
        }

        Log::info('The bot is answering this conversation again.', ['contact_id' => $contact->id]);
    }

    /**
     * Whether the contact said something to us shortly before this echo.
     * Their own message is what tells «the operator answered someone» from
     * «the operator wrote to a stranger».
     */
    private function joinsLiveConversation(ChannelMessage $entry, Contact $contact): bool
    {
        $sentAt = $entry->sent_at ?? $entry->created_at ?? now();

        return ChannelMessage::query()
            ->where('contact_id', $contact->id)
            ->where('direction', ChannelDirection::Inbound)
            ->where('created_at', '<=', $sentAt)
            ->where('created_at', '>=', $sentAt->copy()->subHours(self::LIVE_CONVERSATION_HOURS))
            ->exists();
    }
}
