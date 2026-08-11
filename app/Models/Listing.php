<?php

namespace App\Models;

use App\Enums\ListingMediaType;
use App\Enums\ListingOrigin;
use App\Enums\ListingStatus;
use App\Jobs\GenerateListingEmbedding;
use Database\Factories\ListingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use LogicException;

/**
 * A supplier's offer. Drafted by the AI from free-form supplier input;
 * every business field may stay empty until the supplier completes it via
 * the web interface.
 */
#[Fillable(['contact_id', 'origin', 'created_by_user_id', 'title', 'category_id', 'brand_id', 'description', 'location_id', 'location_detail', 'price', 'status', 'rejection_reason', 'moderated_by_user_id', 'moderated_at', 'expires_at', 'renewal_requested_at'])]
class Listing extends Model
{
    /** @use HasFactory<ListingFactory> */
    use HasFactory;

    /**
     * How long a publication stays active until the supplier re-confirms it.
     */
    public const int LIFETIME_DAYS = 30;

    /**
     * The cap on photos per listing, shared by the supplier cabinet and
     * the admin form. Photos arriving from the bot are not capped.
     */
    public const int MAX_PHOTOS = 10;

    /**
     * The business fields a listing must carry to go live, mapped to the
     * label the operator sees in the «чего не хватает» hint. This is the
     * same set the supplier web form demands before submitting (see
     * UpdateSupplierListingRequest), so a listing published straight from
     * the admin is never thinner than one its supplier completed himself.
     *
     * @var array<string, string>
     */
    public const array PUBLICATION_FIELDS = [
        'title' => 'название',
        'category_id' => 'категория',
        'description' => 'описание',
        'location_id' => 'локация',
        'price' => 'цена',
    ];

    protected $attributes = [
        'status' => ListingStatus::Draft->value,
    ];

    /**
     * The searchable text fields whose change makes the semantic search
     * vector stale (see ListingEmbeddings::sourceText()).
     */
    private const array EMBEDDING_SOURCE_FIELDS = ['status', 'title', 'category_id', 'brand_id', 'description', 'location_id', 'location_detail'];

    /**
     * Media rows go away with the listing via the DB cascade, but the
     * files on disk would be orphaned without this hook.
     *
     * The saved hook keeps the semantic search vector fresh: it fires on
     * approve() (the status transition into search) and on edits of a
     * published listing's searchable text (the operator may edit any
     * status without re-moderation). renew() and archive() do not match.
     */
    protected static function booted(): void
    {
        static::deleting(function (Listing $listing): void {
            $listing->media()->get()->each(
                fn (ListingMedia $media) => Storage::disk($media->disk)->delete($media->path),
            );
        });

        static::saved(function (Listing $listing): void {
            if ($listing->status === ListingStatus::Published && $listing->wasChanged(self::EMBEDDING_SOURCE_FIELDS)) {
                GenerateListingEmbedding::dispatch($listing);
            }
        });
    }

    /** @return BelongsTo<Contact, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    /**
     * The operator who typed the listing in the admin. Empty means the
     * listing came from the chat with the bot — or predates the trail.
     *
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * The operator behind the last verdict — approval, rejection or a
     * publication straight from the admin.
     *
     * @return BelongsTo<User, $this>
     */
    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by_user_id');
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * The name the listing goes by in messages and cabinets: its own
     * title, or the category name for listings drafted before the title
     * field existed. Callers append their context-specific fallback.
     */
    public function displayName(): ?string
    {
        return filled($this->title) ? $this->title : $this->category?->name;
    }

    /**
     * The location as the customer sees it: the KATO node name plus the
     * supplier's free-text detail («г.Шымкент, центр»).
     */
    public function locationLine(): ?string
    {
        $parts = array_filter([$this->location?->name, $this->location_detail]);

        return $parts === [] ? null : implode(', ', $parts);
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

    /** @return HasOne<ListingEmbedding, $this> */
    public function embedding(): HasOne
    {
        return $this->hasOne(ListingEmbedding::class);
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

    /**
     * Listings located inside the given subtree (the node itself
     * included) — the same materialized-path filter customer search
     * uses. A null root applies no filter.
     */
    #[Scope]
    protected function inLocation(Builder $query, ?Location $root): void
    {
        $query->when($root, fn (Builder $builder): Builder => $builder->whereHas(
            'location',
            fn (Builder $location) => $location->where('path', 'like', $root->path.'%'),
        ));
    }

    public function submitForModeration(): void
    {
        $this->assertStatusIn([ListingStatus::Draft, ListingStatus::Rejected], 'submit for moderation');

        $this->update(['status' => ListingStatus::PendingModeration]);
    }

    /**
     * The labels of the publication fields left blank in the given set of
     * values, in the order the operator asks about them on a call. Takes
     * raw values rather than a record so the admin form can hint at what
     * is still missing while the operator is typing, by the same rule the
     * publication itself enforces.
     *
     * @param  array<string, mixed>  $values
     * @return list<string>
     */
    public static function missingPublicationFields(array $values): array
    {
        return array_values(array_filter(
            self::PUBLICATION_FIELDS,
            fn (string $label, string $field): bool => blank($values[$field] ?? null),
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    /**
     * @return list<string>
     */
    public function missingForPublication(): array
    {
        return self::missingPublicationFields($this->only(array_keys(self::PUBLICATION_FIELDS)));
    }

    public function isReadyForPublication(): bool
    {
        return $this->missingForPublication() === [];
    }

    /**
     * Publication by the operator who typed the listing himself: the
     * moderation queue would only add clicks to his own text. The
     * completeness of the business fields replaces the second pair of
     * eyes — an incomplete listing would not reach customer search
     * anyway, and the operator would learn that only afterwards.
     */
    public function publish(?User $author = null): void
    {
        $this->assertStatusIn([ListingStatus::Draft, ListingStatus::Rejected], 'publish');

        throw_unless(
            $this->isReadyForPublication(),
            InvalidArgumentException::class,
            'A listing cannot be published while '.implode(', ', $this->missingForPublication()).' is missing.',
        );

        $this->update([
            'status' => ListingStatus::Published,
            'expires_at' => now()->addDays(self::LIFETIME_DAYS),
            'rejection_reason' => null,
            ...self::verdict($author),
        ]);
    }

    public function approve(?User $author = null): void
    {
        $this->assertStatusIn([ListingStatus::PendingModeration], 'approve');

        $this->update([
            'status' => ListingStatus::Published,
            'expires_at' => now()->addDays(self::LIFETIME_DAYS),
            'rejection_reason' => null,
            ...self::verdict($author),
        ]);
    }

    public function reject(string $reason, ?User $author = null): void
    {
        $this->assertStatusIn([ListingStatus::PendingModeration], 'reject');

        throw_if(blank($reason), new InvalidArgumentException('A rejection reason is required.'));

        $this->update([
            'status' => ListingStatus::Rejected,
            'rejection_reason' => $reason,
            ...self::verdict($author),
        ]);
    }

    /**
     * The trail every verdict leaves. The author is optional: a verdict
     * can also come from a console command, and an unknown author must
     * not stop the transition — it only leaves the trail thinner.
     *
     * @return array{moderated_by_user_id: ?int, moderated_at: \Illuminate\Support\Carbon}
     */
    private static function verdict(?User $author): array
    {
        return [
            'moderated_by_user_id' => $author?->getKey(),
            'moderated_at' => now(),
        ];
    }

    public function archive(): void
    {
        $this->assertStatusIn([ListingStatus::Published], 'archive');

        $this->update(['status' => ListingStatus::Archived]);
    }

    /**
     * The supplier confirmed the listing is still relevant: prolong it
     * without leaving the published status. The renewal poll flag resets
     * so the next 30-day cycle asks again.
     */
    public function renew(): void
    {
        $this->assertStatusIn([ListingStatus::Published], 'renew');

        $this->update([
            'expires_at' => now()->addDays(self::LIFETIME_DAYS),
            'renewal_requested_at' => null,
        ]);
    }

    /**
     * Published listings whose 30-day period ends within a day and that
     * have not been polled in this period yet.
     */
    #[Scope]
    protected function dueForRenewalPoll(Builder $query): void
    {
        $query
            ->where('status', ListingStatus::Published)
            ->whereNull('renewal_requested_at')
            ->where('expires_at', '>', now())
            ->where('expires_at', '<=', now()->addDay());
    }

    /**
     * Published listings whose period ran out without a confirmation —
     * they auto-archive («мёртвые души» leave the search).
     */
    #[Scope]
    protected function expiredWithoutConfirmation(Builder $query): void
    {
        $query
            ->where('status', ListingStatus::Published)
            ->where('expires_at', '<=', now());
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
     * @return array{status: class-string<ListingStatus>, origin: class-string<ListingOrigin>, expires_at: 'datetime', renewal_requested_at: 'datetime', moderated_at: 'datetime'}
     */
    protected function casts(): array
    {
        return [
            'status' => ListingStatus::class,
            'origin' => ListingOrigin::class,
            'expires_at' => 'datetime',
            'renewal_requested_at' => 'datetime',
            'moderated_at' => 'datetime',
        ];
    }
}
