<?php

namespace App\Filament\Resources\WhatsappTemplates;

use App\Filament\Clusters\WhatsApp\WhatsAppCluster;
use App\Filament\Resources\WhatsappTemplates\Pages\CreateWhatsappTemplate;
use App\Filament\Resources\WhatsappTemplates\Pages\ListWhatsappTemplates;
use App\Filament\Resources\WhatsappTemplates\Schemas\WhatsappTemplateForm;
use App\Filament\Resources\WhatsappTemplates\Tables\WhatsappTemplatesTable;
use App\Models\WhatsappTemplate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Registry of the number's WhatsApp Template Messages. Templates are
 * created here (registered with Meta through Dereu) or pulled in by the
 * sync action; the moderation verdict arrives asynchronously from Meta.
 * A template cannot be edited — Meta requires a new template instead.
 */
class WhatsappTemplateResource extends Resource
{
    protected static ?string $model = WhatsappTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $cluster = WhatsAppCluster::class;

    protected static ?string $slug = 'templates';

    protected static ?string $modelLabel = 'шаблон WhatsApp';

    protected static ?string $pluralModelLabel = 'шаблоны WhatsApp';

    protected static ?string $navigationLabel = 'Шаблоны';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return WhatsappTemplateForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WhatsappTemplatesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWhatsappTemplates::route('/'),
            'create' => CreateWhatsappTemplate::route('/create'),
        ];
    }
}
