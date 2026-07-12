<?php

namespace App\Services;

use App\Enums\WhatsappTemplateCategory;
use App\Models\WhatsappTemplate;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Built-in catalog of the project's standard Template Messages — the ones
 * the business rules rely on (the 30-day relevance poll, the customer
 * request notification, the operator's fleet update broadcast). The
 * operator adds them to the registry in one click instead of typing the
 * texts by hand; flows reference them by the name constants.
 *
 * @phpstan-type LibraryEntry array{name: string, language: string, category: WhatsappTemplateCategory, title: string, purpose: string, body: string, quick_replies: list<string>, examples: list<string>}
 */
class WhatsappTemplateLibrary
{
    /** The 30-day relevance poll sent a day before a listing expires. */
    public const string LISTING_RENEWAL = 'listing_renewal';

    /** Notifies a supplier about a new customer request outside the 24-hour window. */
    public const string NEW_CUSTOMER_REQUEST = 'new_customer_request';

    /** The operator's mass broadcast asking suppliers to refresh their fleet. */
    public const string FLEET_STATUS_UPDATE = 'fleet_status_update';

    public function __construct(private readonly WhatsappTemplateRegistry $registry) {}

    /**
     * @return Collection<int, LibraryEntry>
     */
    public function all(): Collection
    {
        return collect([
            [
                'name' => self::LISTING_RENEWAL,
                'language' => 'ru',
                'category' => WhatsappTemplateCategory::Utility,
                'title' => '30-дневный опрос актуальности',
                'purpose' => 'Уходит поставщику за сутки до окончания публикации: подтвердить актуальность или отправить объявление в архив.',
                'body' => 'Ваше объявление «{{1}}» скоро перестанет показываться в поиске. Оно ещё актуально?',
                'quick_replies' => ['Да, актуально', 'Нет, в архив'],
                'examples' => ['Автокран 25 т'],
            ],
            [
                'name' => self::NEW_CUSTOMER_REQUEST,
                'language' => 'ru',
                'category' => WhatsappTemplateCategory::Utility,
                'title' => 'Новая заявка по объявлению',
                'purpose' => 'Уведомляет поставщика о заявке заказчика, когда 24-часовое окно закрыто и обычное сообщение не доставить.',
                'body' => 'По вашему объявлению «{{1}}» новая заявка от заказчика: «{{2}}». Готовы взять заказ?',
                'quick_replies' => ['Согласиться', 'Отказаться'],
                'examples' => ['Автокран 25 т', 'Нужен кран на завтра, Шымкент'],
            ],
            [
                'name' => self::FLEET_STATUS_UPDATE,
                'language' => 'ru',
                'category' => WhatsappTemplateCategory::Marketing,
                'title' => 'Актуализация парка (рассылка)',
                'purpose' => 'Массовая рассылка оператора с просьбой обновить объявления. Ответ поставщика откроет 24-часовое окно — бот пришлёт персональную ссылку на кабинет.',
                'body' => 'Здравствуйте! Мы обновляем каталог техники и услуг. Нажмите кнопку ниже — и мы пришлём ссылку для обновления ваших объявлений.',
                'quick_replies' => ['Обновить объявления'],
                'examples' => [],
            ],
        ]);
    }

    /**
     * Library entries that are not in the local registry yet.
     *
     * @return Collection<int, LibraryEntry>
     */
    public function missing(): Collection
    {
        $registered = WhatsappTemplate::query()
            ->get(['name', 'language'])
            ->map(fn (WhatsappTemplate $template): string => $template->name.'|'.$template->language);

        return $this->all()
            ->reject(fn (array $entry): bool => $registered->contains($entry['name'].'|'.$entry['language']))
            ->values();
    }

    /**
     * Register a library template with Meta (through Dereu) and store the
     * pending registry row.
     */
    public function add(string $name): WhatsappTemplate
    {
        /** @var LibraryEntry|null $entry */
        $entry = $this->all()->firstWhere('name', $name);

        throw_if($entry === null, new InvalidArgumentException("Unknown library template \"{$name}\"."));

        return $this->registry->create(
            name: $entry['name'],
            language: $entry['language'],
            category: $entry['category'],
            body: $entry['body'],
            components: $entry['quick_replies'] === [] ? null : [[
                'type' => 'BUTTONS',
                'buttons' => array_map(
                    fn (string $text): array => ['type' => 'QUICK_REPLY', 'text' => $text],
                    $entry['quick_replies'],
                ),
            ]],
            example: $entry['examples'] === [] ? null : ['body_text' => [$entry['examples']]],
        );
    }
}
