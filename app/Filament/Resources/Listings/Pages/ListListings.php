<?php

namespace App\Filament\Resources\Listings\Pages;

use App\Enums\ListingStatus;
use App\Filament\Resources\Listings\ListingResource;
use App\Models\Listing;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Builder;

class ListListings extends ListRecords
{
    protected static string $resource = ListingResource::class;

    /**
     * The listing table is the widest screen in the panel, so this page alone
     * drops the default 7xl cap and takes the whole window. Every other page
     * keeps the panel default.
     */
    protected Width|string|null $maxContentWidth = Width::Full;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Новое объявление'),
        ];
    }

    /**
     * The operator's two working sets — the moderation queue and his own
     * unfinished drafts — one click apart, but neither of them preselected:
     * the list opens on «Все», so a listing he has just typed for a client
     * on the phone is on the first screen he lands on. The remaining
     * statuses stay behind the status filter.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Все'),
            'moderation' => Tab::make('На модерации')
                // An empty queue is a normal state, and a «0» would read as
                // an unread counter that never clears.
                ->badge(fn (): ?string => ($waiting = Listing::query()
                    ->where('status', ListingStatus::PendingModeration)
                    ->count()) > 0
                        ? (string) $waiting
                        : null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', ListingStatus::PendingModeration)),
            'drafts' => Tab::make('Черновики')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', ListingStatus::Draft)),
        ];
    }
}
