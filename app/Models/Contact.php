<?php

namespace App\Models;

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
     * @return array{last_inbound_at: 'datetime'}
     */
    protected function casts(): array
    {
        return [
            'last_inbound_at' => 'datetime',
        ];
    }
}
