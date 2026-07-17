<?php

namespace App\Services\Ai;

use App\Enums\AiOutcome;
use App\Enums\BotScenarioTrigger;
use App\Models\BotScenario;
use App\Models\BotSession;
use App\Models\CustomerRequest;
use App\Models\Listing;
use App\Models\Location;
use App\Services\Bot\InboundMessage;
use App\Services\Bot\ScenarioRunner;
use App\Services\CustomerRequestNotifier;
use App\Services\DereuMessenger;
use App\Services\Locations\LocationResolver;
use App\Support\WhatsappText;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The customer branch of the AI module (docs/modules/ai-assistant.md):
 * asks what the customer needs (typed or as a voice message, transcribed
 * upstream by ScenarioAiAssistant), matches the query against published
 * listings, offers the ranked results as a WhatsApp list, and turns the
 * chosen option into a customer request with a supplier notification.
 * Equipment is never locked by a request.
 */
class CustomerSearchAssistant
{
    /**
     * Fruitless searches before the block gives up and releases the
     * contact back to the scenario (mirrors the collector's limit).
     */
    private const int MAX_FRUITLESS_SEARCHES = 3;

    private const string ROW_ID_PREFIX = 'listing:';

    /** WhatsApp limits: list row title 24 chars, description 72, button 20. */
    private const int ROW_TITLE_LIMIT = 24;

    private const int ROW_DESCRIPTION_LIMIT = 72;

    public const string LIST_BUTTON = 'Варианты';

    public const string BUTTON_EXPAND = 'search_expand';

    public const string BUTTON_EXPAND_TITLE = 'Искать шире';

    /** Releases the contact from a dead-end search back to the main dialog. */
    public const string BUTTON_MENU = 'search_to_menu';

    public const string BUTTON_MENU_TITLE = 'В меню';

    private const string QUERY_EXAMPLE = 'например: «кран 25 тонн, Шымкент»';

    public function __construct(
        private readonly DereuMessenger $messenger,
        private readonly ListingMatcher $matcher,
        private readonly ScenarioRunner $runner,
        private readonly CustomerRequestNotifier $notifier,
        private readonly LocationResolver $locations,
    ) {}

    /**
     * @param  array<string, mixed>  $node
     */
    public function start(BotSession $session, array $node): AiOutcome
    {
        $session->state = ['phase' => 'searching', 'attempts' => 0, 'query' => null, 'offered' => [], 'expand_location_id' => null];
        $session->save();

        $this->messenger->sendText(
            $session->contact,
            'Опишите, что вам нужно и в каком городе или районе — например: «нужен кран 25 тонн, Шымкент».',
        );

        return AiOutcome::InProgress;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    public function resume(BotSession $session, array $node, InboundMessage $message): AiOutcome
    {
        $state = is_array($session->state) ? $session->state : [];
        $state += ['phase' => 'searching', 'attempts' => 0, 'query' => null, 'offered' => [], 'expand_location_id' => null];

        // «В меню» from a dead-end search releases the contact to the main
        // dialog regardless of the phase — routed strictly by button id.
        if ($message->replyId === self::BUTTON_MENU) {
            return AiOutcome::Completed;
        }

        if ($state['phase'] === 'choosing') {
            $chosen = $this->matchChoice($state['offered'], $message);

            if ($chosen !== null) {
                return $this->placeRequest($session, $state, $chosen);
            }
        }

        if ($state['phase'] === 'expanding' && $this->matchesExpandButton($message)) {
            return $this->expandSearch($session, $state);
        }

        return $this->search($session, $state, $message);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    protected function search(BotSession $session, array $state, InboundMessage $message): AiOutcome
    {
        $query = trim((string) $message->text);

        if ($query === '' && $message->isVoice()) {
            $query = trim((string) $message->transcription);

            // An unrecognized voice message (silence, download or AI
            // provider failure upstream) never spends a fruitless-search
            // attempt.
            if ($query === '') {
                $this->persist($session, $state);
                $this->messenger->sendText(
                    $session->contact,
                    'Не удалось распознать голосовое сообщение. Напишите, пожалуйста, текстом: что нужно и в каком городе.',
                );

                return AiOutcome::InProgress;
            }
        }

        if ($query === '') {
            $this->persist($session, $state);
            $this->messenger->sendText(
                $session->contact,
                'Опишите, пожалуйста, текстом: что нужно и в каком городе.',
            );

            return AiOutcome::InProgress;
        }

        $location = $this->locations->detectInQuery($query);
        $matches = $this->matcher->match($query, $location);

        if ($matches->isEmpty()) {
            $state['attempts']++;

            if ($state['attempts'] >= self::MAX_FRUITLESS_SEARCHES) {
                $this->persist($session, $state);
                $this->messenger->sendText(
                    $session->contact,
                    'К сожалению, сейчас ничего подходящего не нашлось. Загляните позже — объявления пополняются каждый день.',
                );

                return AiOutcome::Completed;
            }

            // The query named a place with nothing inside: offer to climb
            // one level of the location tree instead of a dead «не нашлось».
            if ($location !== null && $location->parent_id !== null) {
                return $this->offerExpansion($session, $state, $query, $location);
            }

            $this->persist($session, $state);
            $this->sendDeadEnd(
                $session,
                sprintf('По запросу ничего не нашлось. Попробуйте описать иначе — вид техники или услуги и город, %s.', self::QUERY_EXAMPLE),
            );

            return AiOutcome::InProgress;
        }

        return $this->offerMatches($session, $state, $query, $matches);
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  Collection<int, Listing>  $matches
     */
    protected function offerMatches(BotSession $session, array $state, string $query, Collection $matches): AiOutcome
    {
        $state['phase'] = 'choosing';
        $state['query'] = $query;
        $state['offered'] = $matches->pluck('id')->all();
        $state['expand_location_id'] = null;
        $this->persist($session, $state);

        $this->messenger->sendList(
            $session->contact,
            'Вот что нашлось по вашему запросу. Выберите вариант — и мы отправим заявку поставщику:',
            self::LIST_BUTTON,
            $matches->map(fn (Listing $listing): array => $this->listRow($listing))->all(),
        );

        return AiOutcome::InProgress;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    protected function offerExpansion(BotSession $session, array $state, string $query, Location $location): AiOutcome
    {
        $parent = $location->parent;

        $state['phase'] = 'expanding';
        $state['query'] = $query;
        $state['expand_location_id'] = $parent->id;
        $this->persist($session, $state);

        $this->messenger->sendButtons(
            $session->contact,
            sprintf('По «%s» сейчас ничего нет. Поискать шире — %s?', $location->name, $parent->name),
            [['id' => self::BUTTON_EXPAND, 'title' => self::BUTTON_EXPAND_TITLE]],
        );

        return AiOutcome::InProgress;
    }

    /**
     * Re-runs the saved query one location level up. Expanding is free: it
     * is our own suggestion, so it never spends a fruitless-search attempt.
     *
     * @param  array<string, mixed>  $state
     */
    protected function expandSearch(BotSession $session, array $state): AiOutcome
    {
        $location = Location::find($state['expand_location_id']);
        $query = (string) $state['query'];

        if ($location === null || $query === '') {
            return $this->search($session, $state, new InboundMessage(text: $query));
        }

        $matches = $this->matcher->match($query, $location);

        if ($matches->isNotEmpty()) {
            return $this->offerMatches($session, $state, $query, $matches);
        }

        if ($location->parent_id !== null) {
            $parent = $location->parent;
            $state['expand_location_id'] = $parent->id;
            $this->persist($session, $state);

            $this->messenger->sendButtons(
                $session->contact,
                sprintf('По «%s» тоже пусто. Поискать ещё шире — %s?', $location->name, $parent->name),
                [['id' => self::BUTTON_EXPAND, 'title' => self::BUTTON_EXPAND_TITLE]],
            );

            return AiOutcome::InProgress;
        }

        $state['phase'] = 'searching';
        $state['expand_location_id'] = null;
        $this->persist($session, $state);
        $this->sendDeadEnd(
            $session,
            sprintf('Шире искать уже некуда — по всей стране ничего не нашлось. Попробуйте описать иначе, %s.', self::QUERY_EXAMPLE),
        );

        return AiOutcome::InProgress;
    }

    /**
     * A fruitless search that still waits for the contact: the prompt to
     * rephrase plus a «В меню» button so the contact is never stuck without
     * a way back to the main dialog.
     */
    protected function sendDeadEnd(BotSession $session, string $text): void
    {
        $this->messenger->sendButtons(
            $session->contact,
            $text,
            [['id' => self::BUTTON_MENU, 'title' => self::BUTTON_MENU_TITLE]],
        );
    }

    protected function matchesExpandButton(InboundMessage $message): bool
    {
        return $message->replyId === self::BUTTON_EXPAND
            || mb_strtolower(trim((string) $message->text)) === mb_strtolower(self::BUTTON_EXPAND_TITLE);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    protected function placeRequest(BotSession $session, array $state, Listing $listing): AiOutcome
    {
        $request = CustomerRequest::create([
            'contact_id' => $session->contact->id,
            'listing_id' => $listing->id,
            'query_text' => (string) $state['query'],
        ]);

        $this->notifySupplier($request);

        $this->messenger->sendText(
            $session->contact,
            sprintf(
                'Заявка по варианту «%s» отправлена поставщику. Как только он ответит, мы сразу сообщим вам.',
                $listing->category?->name ?: 'объявление',
            ),
        );

        return AiOutcome::Completed;
    }

    /**
     * The published «Новая заявка» scenario orchestrates the supplier
     * notification as an isolated run; while none is published, the
     * legacy hardcoded notifier keeps the flow working.
     */
    protected function notifySupplier(CustomerRequest $request): void
    {
        $scenario = BotScenario::publishedForTrigger(BotScenarioTrigger::NewCustomerRequest);

        if ($scenario !== null) {
            $this->runner->launch($scenario, $request->listing->supplier, $request);

            return;
        }

        $this->notifier->notifySupplier($request);
    }

    /**
     * A picked list row (by machine id), or a typed text that exactly
     * matches the title of exactly one offered row — the scenario-wide
     * convention that typing a button's name equals pressing it. Anything
     * else is treated as a refined search query.
     *
     * @param  list<int>  $offered
     */
    protected function matchChoice(array $offered, InboundMessage $message): ?Listing
    {
        $replyId = (string) $message->replyId;

        if (str_starts_with($replyId, self::ROW_ID_PREFIX)) {
            $id = (int) Str::after($replyId, self::ROW_ID_PREFIX);

            return in_array($id, $offered, true) ? Listing::query()->searchable()->find($id) : null;
        }

        $text = trim(Str::lower((string) $message->text));

        if ($text === '') {
            return null;
        }

        /** @var Collection<int, Listing> $byTitle */
        $byTitle = Listing::query()->searchable()->whereIn('id', $offered)->get()
            ->filter(fn (Listing $listing): bool => Str::lower($this->rowTitle($listing)) === $text);

        return $byTitle->count() === 1 ? $byTitle->first() : null;
    }

    /**
     * @return array{id: string, title: string, description?: string}
     */
    protected function listRow(Listing $listing): array
    {
        $row = [
            'id' => self::ROW_ID_PREFIX.$listing->id,
            'title' => $this->rowTitle($listing),
        ];

        $description = implode(' · ', array_filter([$listing->locationLine(), $listing->price]));

        if ($description !== '') {
            $row['description'] = WhatsappText::clamp($description, self::ROW_DESCRIPTION_LIMIT);
        }

        return $row;
    }

    protected function rowTitle(Listing $listing): string
    {
        return WhatsappText::clamp($listing->category?->name ?: 'Объявление №'.$listing->id, self::ROW_TITLE_LIMIT);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    protected function persist(BotSession $session, array $state): void
    {
        $session->state = $state;
        $session->save();
    }
}
