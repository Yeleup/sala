<?php

namespace App\Services;

use App\Enums\ChannelDirection;
use App\Enums\ChannelMessageAuthor;
use App\Enums\ChannelMessageStatus;
use App\Models\ChannelMessage;
use App\Models\Contact;
use App\Models\DereuWebhookEvent;
use App\Support\PhoneNumber;
use Illuminate\Support\Carbon;

/**
 * Puts a message the operator sent from the WhatsApp Business app into the
 * channel journal, where the rest of the conversation already lives.
 *
 * The number is shared: the bot talks through the API, a person talks
 * through the app on their phone, and the contact sees one chat. Only one
 * of those two halves was being recorded, so the operator opening «Чат» in
 * the admin saw the bot arguing with someone and none of their own replies
 * — on 27 August the bot showed one contact the menu five times while the
 * operator was rescuing that same conversation by voice, and the admin
 * showed only the bot's half.
 *
 * The single place that reads the echo envelope, shared by the webhook job
 * and the backfill command.
 */
class OperatorEchoJournal
{
    /**
     * Journal the event's echo, returning the entry — or null when the
     * envelope carries nothing usable (no echo, no id, no recognisable
     * recipient). A repeat of the same echo returns the existing row: the
     * wamid Meta assigned is what makes this idempotent.
     */
    public function record(DereuWebhookEvent $event): ?ChannelMessage
    {
        $echo = $this->echo($event);
        $wamid = $echo['id'] ?? null;

        if ($echo === null || ! is_string($wamid) || $wamid === '') {
            return null;
        }

        $contact = $this->contact($event, $echo);

        if ($contact === null) {
            return null;
        }

        // По автору тоже: пространство wamid общее с ботом — ровно поэтому
        // уникальный индекс частичный. Без этого условия эхо на отправку
        // самого бота вернуло бы его строку, и пауза «оператор ведёт
        // диалог» включилась бы от сообщения бота.
        $entry = ChannelMessage::query()->firstOrNew([
            'direction' => ChannelDirection::Outbound,
            'author' => ChannelMessageAuthor::Operator,
            'wamid' => $wamid,
        ]);

        if ($entry->exists) {
            return $entry;
        }

        $type = (string) ($echo['type'] ?? 'unknown');
        $sentAt = $this->sentAt($echo);

        $entry->fill([
            'contact_id' => $contact->id,
            'type' => $type,
            'text' => $this->text($echo, $type),
            'payload' => $echo,
            // Messages sent from the app get no delivery events of ours
            // (those are correlated by the message_id of our own send,
            // which these never had), and Meta bills them on its own side
            // — so no status beyond «ушло» and no cost.
            'status' => ChannelMessageStatus::Sent,
            'sent_at' => $sentAt,
        ]);

        // Timestamps are the message's own, not this moment's: the echo can
        // lag, and a backfill writes a month of history in one pass. The
        // thread is ordered by created_at, so getting this wrong would pile
        // old messages under today's date separator.
        $entry->forceFill(['created_at' => $sentAt, 'updated_at' => $sentAt])->save();

        return $entry;
    }

    /**
     * The contact the operator wrote to, created when the operator wrote
     * first: they are talking to a lead, and a lead with no chat page is
     * exactly what used to be invisible. The 24-hour window is untouched —
     * this is an outbound message, and Meta does not open a window for it.
     *
     * @param  array<string, mixed>  $echo
     */
    private function contact(DereuWebhookEvent $event, array $echo): ?Contact
    {
        $raw = $event->payload['payload']['contacts'][0]['wa_id'] ?? $echo['to'] ?? null;
        $phone = is_string($raw) ? PhoneNumber::normalize($raw) : null;

        return $phone === null ? null : Contact::query()->firstOrCreate(['phone' => $phone]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function echo(DereuWebhookEvent $event): ?array
    {
        $echo = $event->payload['payload']['message_echoes'][0] ?? null;

        return is_array($echo) ? $echo : null;
    }

    /**
     * @param  array<string, mixed>  $echo
     */
    private function text(array $echo, string $type): ?string
    {
        $body = $echo['text']['body'] ?? $echo[$type]['caption'] ?? $echo['caption'] ?? null;

        return is_string($body) ? $body : null;
    }

    /**
     * @param  array<string, mixed>  $echo
     */
    private function sentAt(array $echo): Carbon
    {
        // The echo can lag behind the send, and a backfill writes months-old
        // messages today — the thread is ordered by this, so it has to be
        // when the message was actually sent.
        return isset($echo['timestamp'])
            ? Carbon::createFromTimestamp((int) $echo['timestamp'])
            : now();
    }
}
