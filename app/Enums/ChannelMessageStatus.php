<?php

namespace App\Enums;

/**
 * Delivery lifecycle of a journal entry. Inbound messages are always
 * «received»; outbound ones move queued → sent → delivered → read (or
 * failed). Statuses never move backwards — Dereu may redeliver webhook
 * events out of order.
 */
enum ChannelMessageStatus: string
{
    case Received = 'received';
    case Queued = 'queued';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Read = 'read';
    case Failed = 'failed';

    public function rank(): int
    {
        return match ($this) {
            self::Received, self::Queued => 0,
            self::Sent => 1,
            self::Delivered => 2,
            self::Read => 3,
            self::Failed => 4,
        };
    }
}
