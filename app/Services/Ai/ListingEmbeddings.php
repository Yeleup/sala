<?php

namespace App\Services\Ai;

use App\Enums\AiOperationType;
use App\Enums\ListingKind;
use App\Models\Listing;
use App\Services\Ai\Audit\AiAudit;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Embeddings;
use Throwable;

/**
 * Semantic search vectors for listings: the source text a listing is
 * embedded from, its staleness fingerprint, and the customer query
 * vector for hybrid search.
 */
class ListingEmbeddings
{
    /**
     * Vector size for every stored and query embedding. Must match the
     * dimension of the listing_embeddings vector column.
     */
    public const int DIMENSIONS = 1536;

    public function __construct(private readonly AiAudit $audit) {}

    /**
     * The text a listing's vector is generated from: everything a
     * free-form customer request may semantically refer to, assembled
     * from the fields of the listing's kind. The location stays in so a
     * query location the resolver did not recognize still matches
     * semantically; the price, the driver's experience years and licence
     * type are left out (numeric/enum noise for embeddings — structural
     * filters and the keyword score cover them).
     */
    public function sourceText(Listing $listing): string
    {
        $kindLines = match ($listing->kind) {
            ListingKind::Rental => [
                $listing->category === null ? null : 'Категория: '.$listing->category->name,
                $listing->brand === null ? null : 'Марка: '.$listing->brand->name,
            ],
            ListingKind::Repair => [
                blank($listing->person_name) ? null : 'Мастер: '.$listing->person_name,
                blank($listing->services) ? null : 'Услуги: '.$listing->services,
            ],
            ListingKind::Driver => [
                blank($listing->person_name) ? null : 'Водитель: '.$listing->person_name,
                $listing->machineCategories->isEmpty() ? null : 'Техника: '.$listing->machineCategories->pluck('name')->implode(', '),
            ],
        };

        return implode("\n", array_filter([
            blank($listing->title) ? null : 'Название: '.$listing->title,
            ...$kindLines,
            blank($listing->description) ? null : 'Описание: '.$listing->description,
            $listing->locationLine() === null ? null : 'Локация: '.$listing->locationLine(),
        ]));
    }

    /**
     * Fingerprint of what a stored vector was computed from — an
     * unchanged hash means re-embedding would waste an API call.
     */
    public function sourceHash(Listing $listing, string $model): string
    {
        return hash('sha256', $model."\n".$this->sourceText($listing));
    }

    /**
     * The customer query vector. Cached (customers repeat the same short
     * queries), audited, and null on any failure — the matcher then
     * degrades to keyword-only matching.
     *
     * @return array<float>|null
     */
    public function queryVector(string $query): ?array
    {
        if (blank($query)) {
            return null;
        }

        try {
            return $this->audit->run(
                AiOperationType::Embedding,
                fn (): array => Embeddings::for([$query])
                    ->dimensions(self::DIMENSIONS)
                    ->cache()
                    ->generate()
                    ->first(),
            );
        } catch (Throwable $e) {
            Log::warning('Query embedding failed, falling back to keyword-only search.', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
