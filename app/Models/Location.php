<?php

namespace App\Models;

use App\Services\Locations\LocationName;
use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A node of the KATO administrative-territorial tree (oblast → district /
 * city administration → rural okrug → settlement). Listings reference a
 * node of any level; customer search matches whole subtrees via `path`.
 */
#[Fillable(['parent_id', 'name', 'search_name', 'path', 'depth'])]
class Location extends Model
{
    /** @use HasFactory<LocationFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (Location $location): void {
            if (blank($location->search_name)) {
                $location->search_name = LocationName::searchKey($location->name);
            }

            // `depth` is unset (null) unless the importer passed it along.
            if ($location->parent_id !== null && blank($location->depth)) {
                $location->depth = $location->resolvedParent()->depth + 1;
            }
        });

        // A rename must stay findable: the search key follows the name.
        static::updating(function (Location $location): void {
            if ($location->isDirty('name') && ! $location->isDirty('search_name')) {
                $location->search_name = LocationName::searchKey($location->name);
            }
        });

        // The materialized path needs the node's own id, hence post-insert.
        static::created(function (Location $location): void {
            $parentPath = $location->parent_id === null ? '/' : $location->resolvedParent()->path;

            $location->updateQuietly(['path' => $parentPath.$location->id.'/']);
        });
    }

    /** @return BelongsTo<Location, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<Location, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return HasMany<Listing, $this> */
    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }

    /**
     * The node with all its descendants — one indexed LIKE over the
     * materialized path.
     */
    #[Scope]
    protected function withinSubtree(Builder $query, Location $root): void
    {
        $query->where('path', 'like', $root->path.'%');
    }

    public function isDescendantOf(Location $other): bool
    {
        return $this->path !== $other->path && str_starts_with($this->path, $other->path);
    }

    /**
     * @return Collection<int, Location>
     */
    public function ancestors(): Collection
    {
        $ids = array_slice(array_values(array_filter(explode('/', $this->path))), 0, -1);

        return self::query()->whereIn('id', $ids)->orderBy('depth')->get();
    }

    /**
     * Full human-readable chain: «с.Аксуат, район Ақсуат, область Абай».
     */
    public function label(): string
    {
        return $this->ancestors()
            ->sortByDesc('depth')
            ->pluck('name')
            ->prepend($this->name)
            ->implode(', ');
    }

    protected function resolvedParent(): Location
    {
        return $this->relationLoaded('parent') && $this->parent !== null
            ? $this->parent
            : self::query()->findOrFail($this->parent_id);
    }
}
