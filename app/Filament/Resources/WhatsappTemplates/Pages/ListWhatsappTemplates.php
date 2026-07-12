<?php

namespace App\Filament\Resources\WhatsappTemplates\Pages;

use App\Filament\Resources\WhatsappTemplates\WhatsappTemplateResource;
use App\Services\WhatsappTemplateLibrary;
use App\Services\WhatsappTemplateRegistry;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Throwable;

class ListWhatsappTemplates extends ListRecords
{
    protected static string $resource = WhatsappTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->libraryAction(),
            Action::make('sync')
                ->label('Синхронизировать с Meta')
                ->icon(Heroicon::OutlinedArrowPath)
                ->action(function (): void {
                    try {
                        $count = app(WhatsappTemplateRegistry::class)->sync();
                    } catch (Throwable $e) {
                        Notification::make()
                            ->title('Синхронизация не удалась')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title("Шаблоны синхронизированы: {$count}")
                        ->success()
                        ->send();
                }),
            CreateAction::make()->label('Новый шаблон'),
        ];
    }

    /**
     * The built-in catalog of the project's standard templates: the
     * operator ticks the ones to register instead of typing them by hand.
     */
    protected function libraryAction(): Action
    {
        $missing = fn () => app(WhatsappTemplateLibrary::class)->missing();

        return Action::make('library')
            ->label('Библиотека шаблонов')
            ->icon(Heroicon::OutlinedBookOpen)
            ->badge(fn (): ?string => ($count = $missing()->count()) > 0 ? (string) $count : null)
            ->disabled(fn (): bool => $missing()->isEmpty())
            ->tooltip(fn (): ?string => $missing()->isEmpty() ? 'Все шаблоны из библиотеки уже добавлены' : null)
            ->modalHeading('Библиотека готовых шаблонов')
            ->modalDescription('Стандартные шаблоны проекта. Выбранные будут зарегистрированы в Meta через Dereu и появятся в реестре со статусом «На модерации Meta».')
            ->modalSubmitActionLabel('Добавить выбранные')
            ->schema([
                CheckboxList::make('templates')
                    ->label('Шаблоны')
                    ->required()
                    ->options(fn (): array => $missing()
                        ->mapWithKeys(fn (array $entry): array => [$entry['name'] => $entry['title']])
                        ->all())
                    ->descriptions(fn (): array => $missing()
                        ->mapWithKeys(fn (array $entry): array => [$entry['name'] => $entry['purpose'].' Текст: «'.$entry['body'].'»'])
                        ->all()),
            ])
            ->action(function (array $data): void {
                $library = app(WhatsappTemplateLibrary::class);
                $added = [];
                $failures = [];

                foreach ((array) ($data['templates'] ?? []) as $name) {
                    try {
                        $added[] = $library->add($name)->name;
                    } catch (Throwable $e) {
                        $failures[$name] = $e->getMessage();
                    }
                }

                if ($added !== []) {
                    Notification::make()
                        ->title('Добавлено шаблонов: '.count($added))
                        ->body('Ожидают модерации Meta: '.implode(', ', $added))
                        ->success()
                        ->send();
                }

                foreach ($failures as $name => $message) {
                    Notification::make()
                        ->title("Не удалось добавить «{$name}»")
                        ->body($message)
                        ->danger()
                        ->send();
                }
            });
    }
}
