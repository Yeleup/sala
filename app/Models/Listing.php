<?php

namespace App\Models;

use App\Enums\LicenceType;
use App\Enums\ListingKind;
use App\Enums\ListingMediaType;
use App\Enums\ListingOrigin;
use App\Enums\ListingStatus;
use App\Enums\RepairPlace;
use App\Jobs\GenerateListingEmbedding;
use Database\Factories\ListingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use LogicException;

/**
 * A supplier's offer. Drafted by the AI from free-form supplier input;
 * every business field may stay empty until the supplier completes it via
 * the web interface.
 */
#[Fillable(['contact_id', 'origin', 'created_by_user_id', 'kind', 'title', 'category_id', 'brand_id', 'description', 'location_id', 'location_detail', 'price', 'person_name', 'services', 'experience_years', 'repair_place', 'travels_to_other_cities', 'licence_type', 'document_verified_at', 'document_verified_by', 'status', 'rejection_reason', 'moderated_by_user_id', 'moderated_at', 'expires_at', 'renewal_requested_at'])]
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

    protected $attributes = [
        'status' => ListingStatus::Draft->value,
        'kind' => ListingKind::Rental->value,
    ];

    /**
     * The searchable text fields whose change makes the semantic search
     * vector stale (see ListingEmbeddings::sourceText()). The driver's
     * machine categories live on a pivot and fire no attribute event —
     * the places that sync the pivot of a published listing dispatch
     * GenerateListingEmbedding themselves.
     */
    private const array EMBEDDING_SOURCE_FIELDS = ['status', 'kind', 'title', 'category_id', 'brand_id', 'description', 'location_id', 'location_detail', 'person_name', 'services'];

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

    /** @return BelongsToMany<Category, $this> Machines a driver operates. */
    public function machineCategories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class);
    }

    /** @return BelongsTo<User, $this> The operator who verified the driver's document. */
    public function documentVerifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'document_verified_by');
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

    /** @return HasMany<ListingMedia, $this> The non-public licence document. */
    public function documents(): HasMany
    {
        return $this->media()->where('type', ListingMediaType::Document);
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
     * The business fields this listing must carry to go live, mapped to
     * the label the operator sees in the «чего не хватает» hint. The set
     * comes from the listing's kind: a driver's questionnaire has nothing
     * in common with a rental's beyond the title and the location.
     *
     * @return array<string, string>
     */
    public function publicationFields(): array
    {
        return $this->kind->publicationFields();
    }

    /**
     * The labels of the publication fields left blank in the given set of
     * values, in the order the operator asks about them on a call. Takes
     * raw values rather than a record so the admin form can hint at what
     * is still missing while the operator is typing, by the same rule the
     * publication itself enforces. A boolean is never blank: `false` in
     * «готов выезжать» is an answer, not an omission.
     *
     * @param  array<string, mixed>  $values
     * @return list<string>
     */
    public static function missingPublicationFields(ListingKind $kind, array $values): array
    {
        return array_values(array_filter(
            $kind->publicationFields(),
            fn (string $label, string $field): bool => is_bool($values[$field] ?? null)
                ? false
                : blank($values[$field] ?? null),
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    /**
     * @return list<string>
     */
    public function missingForPublication(): array
    {
        $missing = self::missingPublicationFields($this->kind, $this->only(array_keys($this->publicationFields())));

        if ($this->kind->requiresDocument() && $this->documents()->doesntExist()) {
            $missing[] = 'фото документа';
        }

        return $missing;
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
     * @return array{moderated_by_user_id: ?int, moderated_at: Carbon}
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
     * Возврат из архива в поиск: поставщик снял объявление с публикации
     * или дал сроку истечь, а потом передумал. Объявление уже проходило
     * модерацию, а править архивное поставщик не может — содержимое то
     * же самое, поэтому возврат ведёт прямо в поиск на новые 30 дней,
     * без второго круга модерации.
     *
     * Полнота полей здесь не проверяется — по той же причине, что и в
     * renew(): объявление возвращается ровно в том виде, в каком уже
     * было в поиске, и одобрить его в этом виде мог сам оператор
     * (approve() полноту не требует, в отличие от publish()). Проверка
     * запирала бы поставщика в тупике: архивное объявление он не правит.
     */
    public function restoreFromArchive(): void
    {
        $this->assertStatusIn([ListingStatus::Archived], 'restore from the archive');

        $this->update([
            'status' => ListingStatus::Published,
            'expires_at' => now()->addDays(self::LIFETIME_DAYS),
            'renewal_requested_at' => null,
        ]);
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
     * Published listings due for the 30-day relevance poll: unpolled and
     * inside the polling window — the last day of the period plus one
     * grace day past it. The grace day exists for publications whose poll
     * never went out (a send failure, or Meta rejected the accepted
     * message asynchronously and the failure handler unmarked the
     * listing): they get exactly one more cycle to be actually asked
     * before the auto-archive takes them.
     */
    #[Scope]
    protected function dueForRenewalPoll(Builder $query): void
    {
        $query
            ->where('status', ListingStatus::Published)
            ->whereNull('renewal_requested_at')
            ->where('expires_at', '>', now()->subDay())
            ->where('expires_at', '<=', now()->addDay());
    }

    /**
     * Published listings whose period ran out without a confirmation —
     * they auto-archive («мёртвые души» leave the search). A listing that
     * was never actually asked (the poll failed to go out or to deliver)
     * is spared for one grace day so the poll can be retried; after that
     * it archives regardless — an unreachable supplier must not keep a
     * dead listing in the search forever.
     */
    #[Scope]
    protected function expiredWithoutConfirmation(Builder $query): void
    {
        $query
            ->where('status', ListingStatus::Published)
            ->where('expires_at', '<=', now())
            ->where(function (Builder $query): void {
                $query
                    ->whereNotNull('renewal_requested_at')
                    ->orWhere('expires_at', '<=', now()->subDay());
            });
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
     * @return array{status: class-string<ListingStatus>, origin: class-string<ListingOrigin>, kind: class-string<ListingKind>, repair_place: class-string<RepairPlace>, licence_type: class-string<LicenceType>, travels_to_other_cities: 'boolean', expires_at: 'datetime', renewal_requested_at: 'datetime', moderated_at: 'datetime', document_verified_at: 'datetime'}
     */
    protected function casts(): array
    {
        return [
            'status' => ListingStatus::class,
            'origin' => ListingOrigin::class,
            'kind' => ListingKind::class,
            'repair_place' => RepairPlace::class,
            'licence_type' => LicenceType::class,
            'travels_to_other_cities' => 'boolean',
            'expires_at' => 'datetime',
            'renewal_requested_at' => 'datetime',
            'moderated_at' => 'datetime',
            'document_verified_at' => 'datetime',
        ];
    }
}
