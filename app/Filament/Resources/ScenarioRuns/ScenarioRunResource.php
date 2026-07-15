<?php

namespace App\Filament\Resources\ScenarioRuns;

use App\Filament\Resources\ScenarioRuns\Pages\ListScenarioRuns;
use App\Filament\Resources\ScenarioRuns\Tables\ScenarioRunsTable;
use App\Models\ScenarioRun;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Read-only journal of scenario runs: every proactive send (a request
 * notification, a renewal poll, one broadcast recipient) is one run. The
 * operator sees which runs still await a reply, which finished and which
 * failed to deliver.
 */
class ScenarioRunResource extends Resource
{
    protected static ?string $model = ScenarioRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPlayCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Бот';

    protected static ?string $modelLabel = 'запуск сценария';

    protected static ?string $pluralModelLabel = 'запуски сценариев';

    protected static ?string $navigationLabel = 'Запуски';

    protected static ?int $navigationSort = 2;

    public static function table(Table $table): Table
    {
        return ScenarioRunsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListScenarioRuns::route('/'),
        ];
    }
}
