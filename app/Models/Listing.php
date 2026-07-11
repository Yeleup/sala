<?php

namespace App\Models;

use App\Enums\ListingMediaType;
use App\Enums\ListingStatus;
use App\Enums\ListingType;
use Database\Factories\ListingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;
use LogicException;

/**
 * A supplier's offer (equipment or a service). Drafted by the AI from
 * free-form supplier input; every business field except the type may stay
 * empty until the supplier completes it via the web interface.
 */
#[Fillable(['contact_id', 'type', 'category', 'description', 'location', 'price', 'status', 'rejection_reason', 'expires_at'])]
class Listing extends Model
{
    /** @use HasFactory<ListingFactory> */
    use HasFactory;

    /**
     * How long a publication stays active until the supplier re-confirms it.
     */
    public const int LIFETIME_DAYS = 30;

    protected $attributes = [
        'status' => ListingStatus::Draft->value,
    ];

    /** @return BelongsTo<Contact, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    /** @return HasMany<ListingMedia, $this> */
    public function media(): HasMany
    {
        return $this->hasMany(ListingMedia::class);
    }

    /** @return HasMany<ListingMedia, $this> */
    public function photos(): HasMany
    {
        return $this->media()->where('type', ListingMediaType::Photo);
    }

    /** @return HasMany<ListingMedia, $this> */
    public function audioMessages(): HasMany
    {
        return $this->media()->where('type', ListingMediaType::Audio);
    }

    /** @return HasMany<CustomerRequest, $this> */
    public function customerRequests(): HasMany
    {
        return $this->hasMany(CustomerRequest::class);
    }

    /**
     * Listings visible to customer search: published and not expired.
     */
    #[Scope]
    protected function searchable(Builder $query): void
    {
        $query
            ->where('status', ListingStatus::Published)
            ->where('expires_at', '>', now());
    }

    public function submitForModeration(): void
    {
        $this->assertStatusIn([ListingStatus::Draft, ListingStatus::Rejected], 'submit for moderation');

        $this->update(['status' => ListingStatus::PendingModeration]);
    }

    public function approve(): void
    {
        $this->assertStatusIn([ListingStatus::PendingModeration], 'approve');

        $this->update([
            'status' => ListingStatus::Published,
            'expires_at' => now()->addDays(self::LIFETIME_DAYS),
            'rejection_reason' => null,
        ]);
    }

    public function reject(string $reason): void
    {
        $this->assertStatusIn([ListingStatus::PendingModeration], 'reject');

        throw_if(blank($reason), new InvalidArgumentException('A rejection reason is required.'));

        $this->update([
            'status' => ListingStatus::Rejected,
            'rejection_reason' => $reason,
        ]);
    }

    public function archive(): void
    {
        $this->assertStatusIn([ListingStatus::Published], 'archive');

        $this->update(['status' => ListingStatus::Archived]);
    }

    /**
     * The supplier confirmed the listing is still relevant: prolong it
     * without leaving the published status.
     */
    public function renew(): void
    {
        $this->assertStatusIn([ListingStatus::Published], 'renew');

        $this->update(['expires_at' => now()->addDays(self::LIFETIME_DAYS)]);
    }

    /**
     * @param  list<ListingStatus>  $allowed
     */
    protected function assertStatusIn(array $allowed, string $action): void
    {
        throw_unless(
            in_array($this->status, $allowed, true),
            LogicException::class,
            sprintf('Cannot %s a listing in the "%s" status.', $action, $this->status->value),
        );
    }

    /**
     * @return array{type: class-string<ListingType>, status: class-string<ListingStatus>, expires_at: 'datetime'}
     */
    protected function casts(): array
    {
        return [
            'type' => ListingType::class,
            'status' => ListingStatus::class,
            'expires_at' => 'datetime',
        ];
    }
}
