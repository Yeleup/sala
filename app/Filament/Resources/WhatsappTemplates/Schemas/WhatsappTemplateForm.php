<?php

namespace App\Filament\Resources\WhatsappTemplates\Schemas;

use App\Enums\WhatsappTemplateCategory;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

/**
 * Creation form of a WhatsApp template. Meta requires an example value for
 * every {{n}} placeholder of the body, or the template is rejected before
 * moderation even starts.
 */
class WhatsappTemplateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Имя шаблона')
                ->helperText('Только строчные латинские буквы, цифры и подчёркивания — например listing_renewal.')
                ->required()
                ->maxLength(512)
                ->regex('/^[a-z0-9_]+$/'),
            TextInput::make('language')
                ->label('Язык')
                ->helperText('Код языка Meta, например ru или kk.')
                ->required()
                ->default('ru')
                ->maxLength(16),
            Select::make('category')
                ->label('Категория')
                ->options(WhatsappTemplateCategory::class)
                ->default(WhatsappTemplateCategory::Utility->value)
                ->required(),
            Textarea::make('body')
                ->label('Текст сообщения')
                ->helperText('Переменные — {{1}}, {{2}}… Для каждой переменной ниже нужен пример значения.')
                ->required()
                ->rows(4)
                ->maxLength(1024),
            Repeater::make('body_examples')
                ->label('Примеры значений переменных')
                ->helperText('По одному примеру на каждую переменную {{n}}, по порядку.')
                ->simple(TextInput::make('value')->required())
                ->addActionLabel('Добавить пример')
                ->default([])
                ->rules([
                    fn (callable $get): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                        $placeholders = preg_match_all('/\{\{\d+\}\}/', (string) $get('body'));
                        // Repeater state is keyed per item until dehydration —
                        // unwrap so the rule sees the plain values.
                        $examples = collect((array) $value)
                            ->map(fn (mixed $item): mixed => is_array($item) ? reset($item) : $item)
                            ->filter(fn (mixed $item): bool => filled($item))
                            ->count();

                        if ($placeholders !== $examples) {
                            $fail("В тексте переменных: {$placeholders}, примеров: {$examples} — их количество должно совпадать.");
                        }
                    },
                ]),
            Repeater::make('quick_replies')
                ->label('Кнопки быстрого ответа')
                ->helperText('До 3 кнопок, до 25 символов каждая. Например «Да, актуально» и «Нет, в архив».')
                ->simple(TextInput::make('text')->required()->maxLength(25))
                ->addActionLabel('Добавить кнопку')
                ->maxItems(3)
                ->default([]),
        ]);
    }
}
