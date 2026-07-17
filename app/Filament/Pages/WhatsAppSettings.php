<?php

namespace App\Filament\Pages;

use App\Enums\DereuCompanyStatus;
use App\Filament\Clusters\WhatsApp\WhatsAppCluster;
use App\Models\DereuCompany;
use App\Services\DereuConnect;
use App\Services\DereuPlatformClient;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Throwable;

/**
 * Settings page that connects the project's single WhatsApp number
 * through Dereu Hosted Embedded Signup.
 *
 * The page itself is the return_url of the signup flow: Dereu redirects
 * back with ?result=<b64url>&sig=<hmac>, which mount() verifies and stores.
 */
class WhatsAppSettings extends Page
{
    protected static ?string $slug = 'settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $cluster = WhatsAppCluster::class;

    protected static ?string $navigationLabel = 'Настройки';

    protected static ?string $title = 'Настройки WhatsApp';

    protected static ?int $navigationSort = 3;

    /**
     * Lifetime of the signed connect payload and its one-time nonce.
     */
    protected const int CONNECT_TTL_SECONDS = 600;

    public function mount(): void
    {
        $result = request()->query('result');
        $signature = request()->query('sig');

        if (is_string($result) && is_string($signature)) {
            $this->finishConnect($result, $signature);
        }
    }

    #[Computed]
    public function company(): ?DereuCompany
    {
        return DereuCompany::current();
    }

    /**
     * Env keys that must be filled before the number can be connected.
     *
     * @return list<string>
     */
    public function missingConfigKeys(): array
    {
        return array_keys(array_filter([
            'DEREU_PLATFORM_KEY' => blank(config('services.dereu.platform_key')),
            'DEREU_WEBHOOK_SECRET' => blank(config('services.dereu.webhook_secret')),
            'DEREU_EXTERNAL_ID' => blank(config('services.dereu.external_id')),
            'DEREU_CONNECT_SECRET' => blank(config('services.dereu.connect.signing_secret')),
            'DEREU_CONNECT_PREFIX' => blank(config('services.dereu.connect.key_prefix')),
        ]));
    }

    /**
     * @return array<Action>
     */
    public function getHeaderActions(): array
    {
        return [
            Action::make('connect')
                ->label('Подключить WhatsApp')
                ->icon(Heroicon::OutlinedLink)
                ->visible(fn (): bool => $this->missingConfigKeys() === [] && ! $this->company?->isConnected())
                ->action(fn () => $this->startConnect()),

            Action::make('connectCoexistence')
                ->label('Подключить в режиме Coexistence')
                ->icon(Heroicon::OutlinedDevicePhoneMobile)
                ->color('gray')
                ->visible(fn (): bool => $this->missingConfigKeys() === [] && ! $this->company?->isConnected())
                ->requiresConfirmation()
                ->modalHeading('Подключить в режиме Coexistence?')
                ->modalDescription('Номер продолжит работать в приложении WhatsApp Business на телефоне параллельно с Cloud API. Условия: номер должен быть заранее зарегистрирован в свежей версии WhatsApp Business App на телефоне; доступность режима зависит от страны номера.')
                ->modalSubmitActionLabel('Подключить')
                ->action(fn () => $this->startConnect(accountMode: 'coexistence')),

            Action::make('reissueApiKey')
                ->label('Перевыпустить API-ключ')
                ->icon(Heroicon::OutlinedKey)
                ->color(fn (): string => $this->company?->hasApiKey() ? 'gray' : 'warning')
                ->visible(fn (): bool => (bool) $this->company?->isConnected())
                ->requiresConfirmation()
                ->modalHeading('Перевыпустить API-ключ?')
                ->modalDescription('Dereu выпустит новый ключ компании, прежний перестанет действовать сразу.')
                ->action(fn () => $this->reissueApiKey()),

            Action::make('disconnect')
                ->label('Отключить номер')
                ->icon(Heroicon::OutlinedNoSymbol)
                ->color('danger')
                ->visible(fn (): bool => (bool) $this->company?->isConnected())
                ->requiresConfirmation()
                ->modalHeading('Отключить WhatsApp?')
                ->modalDescription('Приём и отправка сообщений через этот номер прекратятся сразу. Уже принятые входящие Dereu хранит ещё 30 дней.')
                ->action(fn () => $this->disconnect()),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Callout::make('Интеграция с Dereu не настроена')
                ->description(fn (): string => 'Не заполнены: '.implode(', ', $this->missingConfigKeys()).'. Значения выдаёт оператор Dereu вместе с platform key.')
                ->warning()
                ->visible(fn (): bool => $this->missingConfigKeys() !== []),

            Section::make('Подключённый номер')
                ->description('Номер WhatsApp, через который работает бот.')
                ->icon(Heroicon::OutlinedChatBubbleLeftRight)
                ->visible(fn (): bool => (bool) $this->company?->isConnected())
                ->columns(2)
                ->schema([
                    TextEntry::make('phone_number_id')
                        ->label('ID номера (phone_number_id)')
                        ->state(fn (): ?string => $this->company?->phone_number_id)
                        ->copyable(),
                    TextEntry::make('waba_id')
                        ->label('WABA ID')
                        ->state(fn (): ?string => $this->company?->waba_id)
                        ->copyable(),
                    TextEntry::make('dereu_company_id')
                        ->label('ID компании в Dereu')
                        ->state(fn (): ?string => $this->company?->dereu_company_id)
                        ->copyable(),
                    TextEntry::make('status')
                        ->label('Статус')
                        ->state(fn (): ?DereuCompanyStatus => $this->company?->status)
                        ->badge(),
                    TextEntry::make('connected_at')
                        ->label('Подключён')
                        ->state(fn (): ?string => $this->company?->connected_at?->format('d.m.Y H:i')),
                    TextEntry::make('api_key')
                        ->label('API-ключ компании')
                        ->state(fn (): string => $this->company?->hasApiKey() ? 'Сохранён' : 'Не сохранён')
                        ->badge()
                        ->color(fn (): string => $this->company?->hasApiKey() ? 'success' : 'danger'),
                ]),

            Callout::make('API-ключ не сохранён')
                ->description('Без ключа компании отправка сообщений не работает. Нажмите «Перевыпустить API-ключ», чтобы получить и сохранить новый.')
                ->danger()
                ->visible(fn (): bool => (bool) $this->company?->isConnected() && ! $this->company->hasApiKey()),

            Section::make('Номер не подключён')
                ->description('Нажмите «Подключить WhatsApp»: откроется страница Dereu с Embedded Signup от Meta. После входа в Meta Business и выбора номера вы вернётесь сюда, номер подключится автоматически.')
                ->icon(Heroicon::OutlinedLink)
                ->visible(fn (): bool => ! $this->company?->isConnected())
                ->schema([
                    Text::make(fn (): string => $this->company?->status === DereuCompanyStatus::Deactivated
                        ? 'Прежний номер был отключён. Подключение можно пройти заново в любой момент.'
                        : 'Понадобится доступ к Meta Business и номер телефона, не занятый другим WhatsApp-приложением.'),
                ]),

            Section::make('Приём входящих (webhook)')
                ->description('Один webhook URL и secret на весь проект — задаются при выпуске platform key в личном кабинете Dereu. Путь должен совпадать точно, включая префикс /api.')
                ->icon(Heroicon::OutlinedArrowPath)
                ->collapsible()
                ->collapsed(fn (): bool => (bool) $this->company?->isConnected())
                ->schema([
                    TextEntry::make('webhook_url')
                        ->label('URL вебхука')
                        ->state(fn (): string => route('webhooks.dereu'))
                        ->copyable(),
                    TextEntry::make('return_origin')
                        ->label('Origin для allowed_return_origins')
                        ->state(fn (): string => url('/'))
                        ->copyable(),
                ]),
        ]);
    }

    /**
     * Start Hosted Embedded Signup: remember a one-time nonce and send the
     * browser to the signed connect.dereu.* URL.
     */
    protected function startConnect(?string $accountMode = null): void
    {
        $nonce = Str::random(32);

        Cache::put($this->connectNonceCacheKey($nonce), true, self::CONNECT_TTL_SECONDS);

        $this->redirect(app(DereuConnect::class)->connectUrl(
            externalId: (string) config('services.dereu.external_id'),
            returnUrl: static::getUrl(),
            nonce: $nonce,
            ttlSeconds: self::CONNECT_TTL_SECONDS,
            companyName: (string) config('app.name'),
            accountMode: $accountMode,
        ));
    }

    /**
     * Handle the OUT redirect of Hosted Embedded Signup.
     */
    protected function finishConnect(string $result, string $signature): void
    {
        if ($this->missingConfigKeys() !== []) {
            Notification::make()
                ->title('Интеграция с Dereu не настроена')
                ->danger()
                ->send();

            $this->redirect(static::getUrl());

            return;
        }

        $data = app(DereuConnect::class)->verifyResult($result, $signature);

        if ($data === null) {
            Notification::make()
                ->title('Не удалось проверить ответ Dereu')
                ->body('Подпись ссылки возврата не сошлась. Запустите подключение заново.')
                ->danger()
                ->send();

            $this->redirect(static::getUrl());

            return;
        }

        if (Cache::pull($this->connectNonceCacheKey($data['nonce'])) === null) {
            Notification::make()
                ->title('Ссылка возврата уже использована или устарела')
                ->body('Если номер не появился на странице, запустите подключение заново.')
                ->warning()
                ->send();

            $this->redirect(static::getUrl());

            return;
        }

        if ($data['status'] !== 'connected') {
            Notification::make()
                ->title('Подключение не завершено')
                ->body("Dereu вернул статус «{$data['status']}». Запустите подключение заново.")
                ->danger()
                ->send();

            $this->redirect(static::getUrl());

            return;
        }

        $company = DereuCompany::query()->updateOrCreate(
            ['external_id' => (string) config('services.dereu.external_id')],
            [
                'name' => (string) config('app.name'),
                'dereu_company_id' => $data['dereu_company_id'],
                'waba_id' => $data['waba_id'],
                'phone_number_id' => $data['phone_number_id'],
                'status' => DereuCompanyStatus::Connected,
                'connected_at' => now(),
            ],
        );

        try {
            $company->update(['api_key' => app(DereuPlatformClient::class)->reissueApiKey($company->external_id)]);

            Notification::make()
                ->title('WhatsApp подключён')
                ->success()
                ->send();
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()
                ->title('Номер подключён, но API-ключ не получен')
                ->body('Нажмите «Перевыпустить API-ключ», чтобы получить его повторно.')
                ->warning()
                ->send();
        }

        $this->redirect(static::getUrl());
    }

    protected function reissueApiKey(): void
    {
        $company = $this->company;

        if (! $company) {
            return;
        }

        try {
            $company->update(['api_key' => app(DereuPlatformClient::class)->reissueApiKey($company->external_id)]);
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()
                ->title('Не удалось перевыпустить API-ключ')
                ->body('Dereu отклонил запрос. Проверьте DEREU_PLATFORM_KEY и попробуйте ещё раз.')
                ->danger()
                ->send();

            return;
        }

        unset($this->company);

        Notification::make()
            ->title('API-ключ сохранён')
            ->success()
            ->send();
    }

    protected function disconnect(): void
    {
        $company = $this->company;

        if (! $company) {
            return;
        }

        try {
            app(DereuPlatformClient::class)->deprovisionCompany($company->external_id);
        } catch (RequestException $exception) {
            // 404 — компании нет, 410 — уже деактивирована: нужное состояние достигнуто.
            if (! in_array($exception->response->status(), [404, 410], true)) {
                report($exception);

                Notification::make()
                    ->title('Не удалось отключить номер')
                    ->body('Dereu отклонил запрос. Попробуйте ещё раз.')
                    ->danger()
                    ->send();

                return;
            }
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()
                ->title('Не удалось отключить номер')
                ->body('Dereu недоступен. Попробуйте ещё раз.')
                ->danger()
                ->send();

            return;
        }

        $company->update([
            'status' => DereuCompanyStatus::Deactivated,
            'api_key' => null,
        ]);

        unset($this->company);

        Notification::make()
            ->title('Номер отключён')
            ->success()
            ->send();
    }

    protected function connectNonceCacheKey(string $nonce): string
    {
        return 'dereu:connect-nonce:'.$nonce;
    }
}
