<?php

namespace App\Filament\Resources\Listings\Pages;

use App\Enums\ListingKind;
use App\Enums\ListingStatus;
use App\Filament\Resources\Listings\ListingResource;
use App\Jobs\GenerateListingEmbedding;
use App\Models\Listing;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

/**
 * The only record page: it replaces the read-only view, so the moderation
 * verdict is given from here.
 */
class EditListing extends EditRecord
{
    protected static string $resource = ListingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ListingResource::previewAction(),
            ListingResource::publishAction(),
            ListingResource::submitForModerationAction(),
            ListingResource::approveAction(),
            ListingResource::rejectAction(),
            ListingResource::renewAction(),
            ListingResource::archiveAction(),
            DeleteAction::make()
                ->label('Удалить')
                ->modalHeading('Удалить объявление?')
                ->modalDescription('Объявление удаляется безвозвратно вместе с медиа и заявками по нему.'),
        ];
    }

    /**
     * The verification mark is a pair of audit columns; the form shows
     * them as one virtual «Документ проверен» toggle.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['document_verified'] = filled($data['document_verified_at'] ?? null);

        return $data;
    }

    /**
     * Ticking the toggle stamps who verified and when; a re-save with the
     * mark already standing must not refresh the trail. Unticking clears
     * both columns — the mark is either a full record or nothing.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (($data['document_verified'] ?? false) && blank($this->record->document_verified_at)) {
            $data['document_verified_at'] = now();
            $data['document_verified_by'] = auth()->id();
        } elseif (! ($data['document_verified'] ?? false)) {
            $data['document_verified_at'] = null;
            $data['document_verified_by'] = null;
        }

        unset($data['document_verified']);

        return $data;
    }

    /**
     * A driver's machine categories live on a pivot and fire no attribute
     * event (see Listing::EMBEDDING_SOURCE_FIELDS), so an edit that only
     * re-picks the machines would leave the search vector stale — the page
     * refreshes it itself. The job is idempotent by the source hash, so
     * a save that changed nothing costs no provider call.
     */
    protected function afterSave(): void
    {
        /** @var Listing $record */
        $record = $this->getRecord();

        if ($record->status === ListingStatus::Published && $record->kind === ListingKind::Driver) {
            GenerateListingEmbedding::dispatch($record);
        }
    }
}
