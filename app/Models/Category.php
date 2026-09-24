<?php

namespace App\Models;

use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A listing category (kind of equipment) — a flat dictionary the operator
 * manages in the admin. The supplier web form picks strictly from it.
 *
 * The AI collector may add to it: equipment a supplier named that the
 * dictionary lacks under any spelling becomes a new, unapproved category
 * (approved_at is null) attached to that supplier's listing. An unapproved
 * category stays out of every list and filter — customer search, the
 * catalog, the web form, the admin — until the operator approves a listing
 * it is attached to; a rejected listing takes an orphaned one away with it.
 * Categories the operator adds are approved from the start (the column
 * defaults to the insert time).
 */
#[Fillable(['name', 'approved_at'])]
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    /** @return HasMany<Listing, $this> */
    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }

    /** @return BelongsToMany<Listing, $this> Driver listings that list this machinery. */
    public function machineListings(): BelongsToMany
    {
        return $this->belongsToMany(Listing::class);
    }

    /**
     * Categories every list and filter may offer.
     */
    #[Scope]
    protected function approved(Builder $query): void
    {
        $query->whereNotNull($query->qualifyColumn('approved_at'));
    }

    /**
     * Approved categories plus the given ones whatever their status: a
     * form editing a listing must still show and accept the new category
     * that listing already carries.
     *
     * @param  iterable<int, int|string|null>  $ids
     */
    #[Scope]
    protected function approvedOr(Builder $query, iterable $ids): void
    {
        $ids = collect($ids)->filter()->values()->all();

        $query->where(fn (Builder $builder): Builder => $builder
            ->whereNotNull($builder->qualifyColumn('approved_at'))
            ->when($ids !== [], fn (Builder $either): Builder => $either->orWhereIn($either->qualifyColumn('id'), $ids)));
    }

    #[Scope]
    protected function unapproved(Builder $query): void
    {
        $query->whereNull($query->qualifyColumn('approved_at'));
    }

    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }

    /**
     * The spelling a category name is stored in: trimmed, inner whitespace
     * collapsed, first letter capitalized — «Автобус», not « автобус».
     */
    public static function normalizeName(string $name): string
    {
        return Str::ucfirst(Str::squish($name));
    }

    /**
     * The dictionary entry with this name in any letter case, approved or
     * not — the guard that keeps one kind of equipment one record.
     */
    public static function findByName(string $name): ?self
    {
        $name = self::normalizeName($name);

        return $name === ''
            ? null
            : self::query()->whereRaw('lower(name) = lower(?)', [$name])->orderBy('id')->first();
    }

    /**
     * The category for equipment the AI named: the existing entry when the
     * dictionary already has this name (in any letter case, approved or
     * still new), otherwise a new unapproved one. The meaning — a synonym,
     * a plural, a typo — is matched by the model against the dictionary it
     * is given; this only closes the race of two suppliers naming the same
     * new equipment at once.
     */
    public static function findOrCreateUnapproved(string $name): self
    {
        $name = self::normalizeName($name);

        return self::findByName($name)
            ?? self::query()->createOrFirst(['name' => $name], ['approved_at' => null]);
    }

    /**
     * A category the operator adds by hand. A new one the AI already added
     * under the same name is adopted — approved and given the operator's
     * spelling — rather than tripping over the unique name.
     */
    public static function createByOperator(string $name): self
    {
        $name = self::normalizeName($name);
        $unapproved = self::query()->unapproved()->whereRaw('lower(name) = lower(?)', [$name])->first();

        if ($unapproved !== null) {
            $unapproved->update(['name' => $name, 'approved_at' => now()]);

            return $unapproved;
        }

        return self::query()->create(['name' => $name])->refresh();
    }

    public function approve(): void
    {
        if (! $this->isApproved()) {
            $this->update(['approved_at' => now()]);
        }
    }

    /**
     * Delete new categories nothing refers to any more — the listing they
     * came with was rejected or deleted, or the operator or the supplier
     * swapped them for another category. An approved category is never
     * touched: the operator decides about those.
     */
    public static function pruneUnattachedUnapproved(): void
    {
        self::query()
            ->unapproved()
            ->whereDoesntHave('listings')
            ->whereDoesntHave('machineListings')
            ->delete();
    }

    /**
     * @return array{approved_at: 'datetime'}
     */
    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
        ];
    }
}
