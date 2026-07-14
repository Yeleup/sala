<?php

namespace App\Models;

use App\Enums\ListingType;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A listing category (kind of equipment or service) — an operator-managed
 * dictionary. The AI assistant and the supplier web form pick strictly from
 * this list and never invent new categories.
 *
 * Each category belongs to exactly one listing type; a listing's type must
 * match its category's type, so a resolved category also fixes the type.
 */
#[Fillable(['name', 'type'])]
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    /** @return HasMany<Listing, $this> */
    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }

    /**
     * @return array{type: class-string<ListingType>}
     */
    protected function casts(): array
    {
        return [
            'type' => ListingType::class,
        ];
    }
}
