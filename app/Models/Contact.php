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
#[Fillable(['phone', 'profile_name', 'last_inbound_at'])]
class Contact extends Model
{
    /** @use HasFactory<ContactFactory> */
    use HasFactory;

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
