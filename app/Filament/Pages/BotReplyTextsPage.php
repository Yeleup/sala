<?php

namespace App\Filament\Pages;

use App\Enums\BotReplyKey;
use App\Models\BotReplyText;
use App\Services\Bot\BotReplyTexts;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Настраиваемые встроенные ответы бота (docs/modules/bot-constructor.md):
 * тексты, которые бот отправляет сам, вне блоков сценария — нераспознанное
 * нажатие кнопки, устаревшая кнопка, вопрос завершённого запуска. Пустое
 * поле означает стандартный текст из BotReplyKey.
 *
 * @property-read Schema $form
 */
class BotReplyTextsPage extends Page
{
    protected static ?string $slug = 'bot-replies';

    protected string $view = 'filament.pages.bot-reply-texts';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleBottomCenterText;

    protected static string|UnitEnum|null $navigationGroup = 'Бот';

    protected static ?string $navigationLabel = 'Ответы бота';

    protected static ?string $title = 'Встроенные ответы бота';

    protected static ?int $navigationSort = 3;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(BotReplyText::query()->pluck('text', 'key')->all());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([
                    Text::make('Эти тексты бот отправляет сам, вне блоков сценария: когда WhatsApp не смог передать нажатие кнопки, кнопка устарела или вопрос уже решён. Пустое поле — используется стандартный текст.'),
                    ...array_map(
                        fn (BotReplyKey $key): Textarea => Textarea::make($key->value)
                            ->label($key->label())
                            ->helperText($key->description().' Пустое поле — используется стандартный текст.')
                            ->placeholder($key->default())
                            ->rows(2)
                            ->autosize()
                            ->maxLength(1024),
                        BotReplyKey::cases(),
                    ),
                ])
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make([
                            Action::make('save')->label('Сохранить')->submit('save'),
                        ]),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();

        foreach (BotReplyKey::cases() as $key) {
            $text = trim((string) ($state[$key->value] ?? ''));

            $text === ''
                ? BotReplyText::query()->where('key', $key->value)->delete()
                : BotReplyText::query()->updateOrCreate(['key' => $key->value], ['text' => $text]);
        }

        app(BotReplyTexts::class)->flush();

        Notification::make()
            ->title('Ответы бота сохранены')
            ->success()
            ->send();
    }
}
