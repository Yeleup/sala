<?php

namespace App\Models;

use App\Enums\ChannelDirection;
use App\Enums\ChannelMessageAuthor;
use App\Enums\ChannelMessageStatus;
use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A WhatsApp user who wrote to the bot. The same contact can act both as a
 * supplier and as a customer — the role comes from the scenario branch.
 */
#[Fillable(['phone', 'profile_name', 'display_name', 'last_inbound_at'])]
class Contact extends Model
{
    /** @use HasFactory<ContactFactory> */
    use HasFactory;

    /**
     * The DB cascade would remove the contact's listings behind Eloquent's
     * back, leaving their media files orphaned on disk — so the listings
     * are deleted through the model to fire their own cleanup hook.
     */
    protected static function booted(): void
    {
        static::deleting(function (Contact $contact): void {
            $contact->listings()->get()->each->delete();
        });
    }

    /**
     * The name shown wherever the contact appears (listings, bot messages,
     * admin). The name the contact set themselves wins; otherwise the
     * WhatsApp profile name, which every inbound message keeps refreshing.
     */
    public function displayName(): ?string
    {
        return $this->display_name ?: $this->profile_name;
    }

    /**
     * WhatsApp allows free session messages only within 24 hours of the
     * contact's last inbound message; outside the window only paid
     * template messages are deliverable.
     */
    public function hasOpenSessionWindow(): bool
    {
        return $this->last_inbound_at?->isAfter(now()->subDay()) ?? false;
    }

    /**
     * Whether the contact ever wrote to the bot at all. A contact created
     * by the operator has no inbound messages, so proactive notifications
     * — including the renewal poll — can only reach them as paid
     * templates until they write in themselves.
     */
    public function hasEverWritten(): bool
    {
        return $this->last_inbound_at !== null;
    }

    /**
     * Whether the bot has ever reached this contact — the mark of someone
     * who already knows what this service is, and so should not be greeted
     * as a stranger again.
     *
     * Deliberately not hasEverWritten(): the inbound journal is updated
     * before the engine runs, so that predicate is always true by the time
     * the greeting is decided. And deliberately not the session's own
     * hasCompletedDialog(): the renewal poll, the moderation verdict and
     * the customer request all live in isolated scenario runs and never
     * create a session row, so a supplier who has been getting messages
     * for a month still looked brand new.
     *
     * A failed send does not count — nothing reached the contact. Neither
     * does a message the operator wrote from their own phone: it is
     * outbound too, but the person on the other side has never heard from
     * the bot and still needs telling what this service is. The cold
     * outreach the operator sends by hand is exactly a list of people the
     * bot has never written to — and it ends by asking them to write to
     * the bot.
     */
    public function hasBotHistory(): bool
    {
        return $this->channelMessages()
            ->where('direction', ChannelDirection::Outbound)
            ->where('author', ChannelMessageAuthor::Bot)
            ->where('status', '!=', ChannelMessageStatus::Failed)
            ->exists();
    }

    /** @return HasMany<Listing, $this> */
    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }

    /** @return HasMany<CustomerRequest, $this> */
    public function customerRequests(): HasMany
    {
        return $this->hasMany(CustomerRequest::class);
    }

    /** @return HasMany<ChannelMessage, $this> */
    public function channelMessages(): HasMany
    {
        return $this->hasMany(ChannelMessage::class);
    }

    /**
     * @return array{last_inbound_at: 'datetime', operator_handoff_until: 'datetime'}
     */
    protected function casts(): array
    {
        return [
            'last_inbound_at' => 'datetime',
            'operator_handoff_until' => 'datetime',
        ];
    }
}
