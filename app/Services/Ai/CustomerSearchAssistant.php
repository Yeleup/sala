<?php

namespace App\Services\Ai;

use App\Ai\Agents\SearchQueryExtractionAgent;
use App\Enums\AiOperationType;
use App\Enums\AiOutcome;
use App\Enums\BotScenarioTrigger;
use App\Models\BotScenario;
use App\Models\BotSession;
use App\Models\CustomerRequest;
use App\Models\Listing;
use App\Models\Location;
use App\Services\Ai\Audit\AiAudit;
use App\Services\Bot\InboundMessage;
use App\Services\Bot\ScenarioRunner;
use App\Services\CustomerRequestNotifier;
use App\Services\DereuMessenger;
use App\Services\Locations\LocationResolver;
use App\Support\WhatsappText;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The customer branch of the AI module (docs/modules/ai-assistant.md):
 * collects what the customer needs and where over a short intake dialog
 * (typed or voice messages, transcribed upstream by ScenarioAiAssistant),
 * asking clarifying questions about the missing pieces, and only then
 * matches the settled query against published listings, offers the ranked
 * results as a WhatsApp list, and turns the chosen option into a customer
 * request with a supplier notification. Equipment is never locked by a
 * request.
 */
class CustomerSearchAssistant
{
    /**
     * Fruitless searches before the block gives up and releases the
     * contact back to the scenario (mirrors the collector's limit).
     */
    private const int MAX_FRUITLESS_SEARCHES = 3;

    /**
     * Clarifying questions of the intake before the search runs with
     * whatever was collected (business rule: 2–3 attempts, mirrors the
     * collector's limit).
     */
    private const int MAX_CLARIFICATIONS = 3;

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
        private readonly AiAudit $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $node
     */
    public function start(BotSession $session, array $node): AiOutcome
    {
        $session->state = $this->defaultState();
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
        $state += $this->defaultState();

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
     * The intake step: accumulate the customer's messages, understand
     * what is needed and where, ask about the missing pieces (bounded by
     * the clarification limit), and run the search only once the
     * requirements are settled.
     *
     * @param  array<string, mixed>  $state
     */
    protected function search(BotSession $session, array $state, InboundMessage $message): AiOutcome
    {
        $input = trim((string) $message->text);

        if ($input === '' && $message->isVoice()) {
            $input = trim((string) $message->transcription);

            // An unrecognized voice message (silence, download or AI
            // provider failure upstream) never spends a fruitless-search
            // attempt or a clarifying question.
            if ($input === '') {
                $this->persist($session, $state);
                $this->messenger->sendText(
                    $session->contact,
                    'Не удалось распознать голосовое сообщение. Напишите, пожалуйста, текстом: что нужно и в каком городе.',
                );

                return AiOutcome::InProgress;
            }
        }

        if ($input === '') {
            $this->persist($session, $state);
            $this->messenger->sendText(
                $session->contact,
                'Опишите, пожалуйста, текстом: что нужно и в каком городе.',
            );

            return AiOutcome::InProgress;
        }

        $state['transcript'][] = $input;
        $state['unresolved_location'] = null;

        $requirements = $this->extractRequirements($session, $state);

        // The AI provider is unavailable: degrade to searching the raw
        // text right away — the customer is never left without an answer.
        if ($requirements === null) {
            return $this->runSearch($session, $state, implode(', ', $state['transcript']));
        }

        // The KATO dictionary is the only source of truth for the place:
        // a named location either resolves to a node (the subtree filter,
        // tolerating close distortions of transcribed voice input) or
        // counts as unsettled and gets asked about, instead of being
        // silently dropped into a country-wide search.
        $location = filled($requirements['location'] ?? null)
            ? $this->locations->detectPlace((string) $requirements['location'])
            : null;

        $missing = $this->missingRequirements($requirements, $location);

        if ($missing !== [] && $state['clarifications'] < self::MAX_CLARIFICATIONS) {
            $state['clarifications']++;
            $this->persist($session, $state);
            $this->messenger->sendText($session->contact, $this->clarifyingQuestion($requirements, $missing));

            return AiOutcome::InProgress;
        }

        // The clarification limit ran out with the place still unknown:
        // the search proceeds without a location filter, and the results
        // are labeled so the customer knows the place was not matched.
        if (in_array('location_unresolved', $missing, true)) {
            $state['unresolved_location'] = (string) $requirements['location'];
        }

        return $this->runSearch($session, $state, $this->composeQuery($state, $requirements), $location);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    protected function runSearch(BotSession $session, array $state, string $query, ?Location $location = null): AiOutcome
    {
        $location ??= $this->locations->detectInQuery($query);
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

        $unresolvedLocation = $state['unresolved_location'] ?? null;

        $this->messenger->sendList(
            $session->contact,
            filled($unresolvedLocation)
                ? sprintf('Не нашли «%s» в справочнике мест, поэтому показываем варианты без учёта места. Выберите вариант — и мы отправим заявку поставщику:', $unresolvedLocation)
                : 'Вот что нашлось по вашему запросу. Выберите вариант — и мы отправим заявку поставщику:',
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

        if ($query === '') {
            return $this->search($session, $state, new InboundMessage(text: null));
        }

        // The saved expansion point vanished: the query is already
        // settled, so re-run it without re-entering the intake.
        if ($location === null) {
            return $this->runSearch($session, $state, $query);
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
     * Understand the accumulated customer messages: what is needed and
     * where. Null when the AI provider is unavailable — the caller then
     * searches the raw text instead of blocking the customer.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>|null
     */
    protected function extractRequirements(BotSession $session, array $state): ?array
    {
        try {
            return $this->audit->run(
                AiOperationType::SearchQueryExtraction,
                fn (): array => (new SearchQueryExtractionAgent)
                    ->prompt(implode("\n", $state['transcript']))
                    ->toArray(),
                [
                    'contact_id' => $session->contact_id,
                    'bot_session_id' => $session->id,
                ],
            );
        } catch (Throwable $e) {
            Log::warning('Search intake extraction failed; falling back to the raw query.', [
                'bot_session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * The search waits for the need and the place; an explicit «место не
     * важно» satisfies the place without naming one, while a place named
     * but not found in the dictionary stays unsettled («location_unresolved»).
     *
     * @param  array<string, mixed>  $requirements
     * @return list<string>
     */
    protected function missingRequirements(array $requirements, ?Location $location): array
    {
        $missing = [];

        if (blank($requirements['subject'] ?? null)) {
            $missing[] = 'subject';
        }

        if ((bool) ($requirements['location_any'] ?? false)) {
            return $missing;
        }

        if (blank($requirements['location'] ?? null)) {
            $missing[] = 'location';
        } elseif ($location === null) {
            $missing[] = 'location_unresolved';
        }

        return $missing;
    }

    /**
     * @param  array<string, mixed>  $requirements
     * @param  list<string>  $missing
     */
    protected function clarifyingQuestion(array $requirements, array $missing): string
    {
        // The extractor believes the place is settled, so its question
        // would miss the dictionary lookup failure — same wording as the
        // supplier collector for an unknown place.
        if ($missing[0] === 'location_unresolved') {
            return sprintf(
                'Не нашли «%s» в справочнике мест. Напишите город, район или село точнее.',
                $requirements['location'],
            );
        }

        if (filled($requirements['clarifying_question'] ?? null)) {
            return (string) $requirements['clarifying_question'];
        }

        return $missing[0] === 'subject'
            ? 'Что именно вам нужно — какая техника или услуга?'
            : 'В каком городе или районе нужна техника или услуга?';
    }

    /**
     * The search string the matcher works with: the extracted need plus
     * the named place, or the raw transcript when the intake could not
     * settle the need within the clarification limit.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $requirements
     */
    protected function composeQuery(array $state, array $requirements): string
    {
        $subject = filled($requirements['subject'] ?? null)
            ? (string) $requirements['subject']
            : implode(', ', $state['transcript']);

        return collect([$subject, $requirements['location'] ?? null])
            ->filter(fn (mixed $part): bool => filled($part))
            ->implode(', ');
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultState(): array
    {
        return [
            'phase' => 'searching',
            'attempts' => 0,
            'clarifications' => 0,
            'transcript' => [],
            'query' => null,
            'offered' => [],
            'expand_location_id' => null,
            'unresolved_location' => null,
        ];
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
