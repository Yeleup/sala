<?php

namespace App\Models;

use App\Enums\ListingMediaType;
use Database\Factories\ListingMediaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A media attachment of a listing: a photo, or a voice message with its
 * text transcription. Files live on a local application disk (MVP decision,
 * no external cloud storage).
 */
#[Fillable(['listing_id', 'type', 'disk', 'path', 'transcription'])]
class ListingMedia extends Model
{
    /** @use HasFactory<ListingMediaFactory> */
    use HasFactory;

    /**
     * Upload size cap for a single photo, in kilobytes (supplier cabinet
     * and admin form).
     */
    public const int MAX_PHOTO_KILOBYTES = 10240;

    protected $attributes = [
        'disk' => 'public',
    ];

    /**
     * A media row deleted through Eloquent (supplier cabinet, admin form)
     * takes its file along. DB cascade deletes bypass this hook — Listing
     * removes the files itself before deleting.
     */
    protected static function booted(): void
    {
        static::deleted(function (ListingMedia $media): void {
            Storage::disk($media->disk)->delete($media->path);
        });
    }

    /** @return BelongsTo<Listing, $this> */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function url(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    /**
     * @return array{type: class-string<ListingMediaType>}
     */
    protected function casts(): array
    {
        return [
            'type' => ListingMediaType::class,
        ];
    }
}
