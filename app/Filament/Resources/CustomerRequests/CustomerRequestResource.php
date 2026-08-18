<?php

namespace App\Filament\Resources\CustomerRequests;

use App\Filament\Clusters\Marketplace\MarketplaceCluster;
use App\Filament\Resources\CustomerRequests\Pages\ListCustomerRequests;
use App\Filament\Resources\CustomerRequests\Pages\ViewCustomerRequest;
use App\Filament\Resources\CustomerRequests\Schemas\CustomerRequestInfolist;
use App\Filament\Resources\CustomerRequests\Tables\CustomerRequestsTable;
use App\Models\CustomerRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Requests are created by the bot when a customer picks a listing, and
 * their status is changed by the supplier's reply in WhatsApp. The
 * operator neither creates nor edits them; the single manual action is
 * «Закрыть без ответа» — it releases a stuck pending request so the
 * customer can ask again.
 */
class CustomerRequestResource extends Resource
{
    protected static ?string $model = CustomerRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInbox;

    protected static ?string $cluster = MarketplaceCluster::class;

    protected static ?string $modelLabel = 'заявка';

    protected static ?string $pluralModelLabel = 'заявки';

    protected static ?int $navigationSort = 2;

    public static function infolist(Schema $schema): Schema
    {
        return CustomerRequestInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CustomerRequestsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomerRequests::route('/'),
            'view' => ViewCustomerRequest::route('/{record}'),
        ];
    }
}
