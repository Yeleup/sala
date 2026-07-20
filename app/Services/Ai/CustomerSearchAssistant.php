<?php

namespace App\Services\Ai;

use App\Ai\Agents\SearchQueryExtractionAgent;
use App\Enums\AiOperationType;
use App\Enums\AiOutcome;
use App\Models\BotSession;
use App\Models\Listing;
use App\Models\Location;
use App\Services\Ai\Audit\AiAudit;
use App\Services\Bot\InboundMessage;
use App\Services\CustomerRequestPlacer;
use App\Services\DereuMessenger;
use App\Services\Locations\LocationResolver;
use App\Support\WhatsappText;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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

    public const string LOCATION_ROW_PREFIX = 'search_location:';

    public const string LOCATION_LIST_BUTTON = 'Выбрать место';

    /** WhatsApp limits: list row title 24 chars, description 72, button 20. */
    private const int ROW_TITLE_LIMIT = 24;

    private const int ROW_DESCRIPTION_LIMIT = 72;

    public const string LIST_BUTTON = 'Варианты';

    /**
     * Legacy: the «Искать шире» button of messages sent before the
     * catalog handoff replaced it. New messages never carry it, but taps
     * on the old ones keep working (a free in-chat search one level up),
     * so the constants and the expanding phase stay handled.
     */
    public const string BUTTON_EXPAND = 'search_expand';

    public const string BUTTON_EXPAND_TITLE = 'Искать шире';

    /** Releases the contact from a dead-end search back to the main dialog. */
    public const string BUTTON_MENU = 'search_to_menu';

    public const string BUTTON_MENU_TITLE = 'В меню';

    /** WhatsApp caps URL-button titles at 20 characters. */
    public const string CATALOG_BUTTON_RESULTS = 'Все варианты';

    public const string CATALOG_BUTTON_DEAD_END = 'Открыть каталог';

    private const string QUERY_EXAMPLE = 'например: «кран 25 тонн, Шымкент»';

    public function __construct(
        private readonly DereuMessenger $messenger,
        private readonly ListingMatcher $matcher,
        private readonly CustomerRequestPlacer $placer,
        private readonly CtaLinkBuilder $links,
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

        if ($state['phase'] === 'locating') {
            return $this->handleLocating($session, $state, $message);
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
        $candidates = filled($requirements['location'] ?? null)
            ? $this->locations->placeCandidates((string) $requirements['location'])
            : new EloquentCollection;

        $location = $candidates->count() === 1 ? $candidates->first() : null;

        // The customer already picked one of these same-named places
        // earlier in the dialog: the pick holds across refinements — no
        // repeated list, no wasted round trip.
        if ($location === null && $candidates->count() > 1) {
            $location = $candidates->firstWhere('id', (int) ($state['location_id'] ?? 0));
        }

        $missing = $this->missingRequirements($requirements, $location);

        // Several same-named (or equally close) places tie at one level:
        // a pick list instead of a question, mirroring the supplier
        // collector. Offering the list and picking from it spend neither a
        // clarification nor a fruitless attempt, so the list goes out even
        // with the limit exhausted — there it also outranks the subject
        // question, which can no longer be asked. Within the limit the
        // subject question keeps its priority (missingRequirements orders
        // it first) and the tie is re-detected on the next turn.
        if (in_array('location_unresolved', $missing, true)
            && $candidates->count() > 1
            && $candidates->count() <= LocationResolver::MAX_CANDIDATES
            && ($missing === ['location_unresolved'] || $state['clarifications'] >= self::MAX_CLARIFICATIONS)) {
            return $this->offerLocationChoices($session, $state, $requirements, $candidates);
        }

        if ($missing !== [] && $state['clarifications'] < self::MAX_CLARIFICATIONS) {
            $state['clarifications']++;
            $this->persist($session, $state);
            $this->messenger->sendText($session->contact, $this->clarifyingQuestion($requirements, $missing, $candidates));

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
        // A running search supersedes an open place pick list.
        $state['location_candidates'] = [];

        $location ??= $this->locations->detectInQuery($query);
        $matches = $this->matcher->match($query, $location);

        if ($matches->isEmpty()) {
            $state['attempts']++;

            if ($state['attempts'] >= self::MAX_FRUITLESS_SEARCHES) {
                $this->persist($session, $state);
                $this->sendCatalogCta(
                    $session,
                    'К сожалению, сейчас ничего подходящего не нашлось. Загляните в каталог — там все объявления, база пополняется каждый день.',
                    self::CATALOG_BUTTON_DEAD_END,
                );

                return AiOutcome::Completed;
            }

            // The query named a place with nothing inside: hand off to the
            // catalog one level up instead of a dead «не нашлось».
            if ($location !== null && $location->parent_id !== null) {
                return $this->offerWiderCatalog($session, $state, $query, $location);
            }

            $this->persist($session, $state);
            $this->sendDeadEnd(
                $session,
                sprintf('По запросу ничего не нашлось. Попробуйте описать иначе — вид техники или услуги и город, %s.', self::QUERY_EXAMPLE),
            );

            return AiOutcome::InProgress;
        }

        return $this->offerMatches($session, $state, $query, $matches, $location);
    }

    /**
     * The catalog CTA rides with every результат: the chat list holds at
     * most 10 rows, the catalog shows everything. The prefill mirrors
     * exactly what this search ranked by — the query and the location the
     * matcher actually filtered with (none when the place stayed
     * unresolved).
     *
     * @param  array<string, mixed>  $state
     * @param  Collection<int, Listing>  $matches
     */
    protected function offerMatches(BotSession $session, array $state, string $query, Collection $matches, ?Location $location = null): AiOutcome
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

        $this->sendCatalogCta(
            $session,
            'Показали до 10 самых подходящих вариантов. В каталоге — все объявления: поиск, фильтры по месту и категории, заявка в пару нажатий.',
            self::CATALOG_BUTTON_RESULTS,
            $query,
            $location,
        );

        return AiOutcome::InProgress;
    }

    /**
     * The queried place has nothing inside: a single message with a URL
     * button into the web catalog one level up («село → район»), the
     * query and the wider place already prefilled — instead of a dead
     * «не нашлось». The fruitless attempt was spent on the empty search
     * itself; the dialog stays put and keeps waiting for a refined query.
     *
     * @param  array<string, mixed>  $state
     */
    protected function offerWiderCatalog(BotSession $session, array $state, string $query, Location $location): AiOutcome
    {
        $parent = $location->parent;

        $state['phase'] = 'searching';
        $state['query'] = $query;
        $state['expand_location_id'] = null;
        $this->persist($session, $state);

        $this->sendCatalogCta(
            $session,
            sprintf('По «%s» сейчас ничего нет. Посмотрите шире — в каталоге уже подставлены ваш запрос и «%s».', $location->name, $parent->name),
            self::CATALOG_BUTTON_DEAD_END,
            $query,
            $parent,
        );

        return AiOutcome::InProgress;
    }

    /**
     * Several dictionary places match the named location: the same-named
     * candidates go out as an interactive list (mirroring the supplier
     * collector), identical titles told apart by the ancestor-chain
     * captions. The search itself waits for the pick.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $requirements
     * @param  EloquentCollection<int, Location>  $candidates
     */
    protected function offerLocationChoices(BotSession $session, array $state, array $requirements, EloquentCollection $candidates): AiOutcome
    {
        $state['phase'] = 'locating';
        $state['query'] = $this->composeQuery($state, $requirements);
        $state['location_candidates'] = $candidates->pluck('id')->all();
        $state['offered'] = [];
        $state['expand_location_id'] = null;
        $this->persist($session, $state);

        $this->messenger->sendList(
            $session->contact,
            'Нашли несколько подходящих мест — уточните, в каком из них искать.',
            self::LOCATION_LIST_BUTTON,
            $candidates
                ->map(fn (Location $location): array => array_filter([
                    'id' => self::LOCATION_ROW_PREFIX.$location->id,
                    'title' => WhatsappText::clamp($location->name, self::ROW_TITLE_LIMIT),
                    'description' => WhatsappText::clamp(
                        $location->ancestors()->sortByDesc('depth')->pluck('name')->implode(', '),
                        self::ROW_DESCRIPTION_LIMIT,
                    ) ?: null,
                ]))
                ->values()
                ->all(),
        );

        return AiOutcome::InProgress;
    }

    /**
     * The customer picks one of the same-named places — by the list row or
     * by typing a candidate's name matching exactly one of them (the
     * scenario-wide convention). Same-named candidates cannot be told
     * apart by typed text, so such a reply — like any other text — goes
     * through the normal intake, which re-offers the list.
     *
     * @param  array<string, mixed>  $state
     */
    protected function handleLocating(BotSession $session, array $state, InboundMessage $message): AiOutcome
    {
        $candidates = array_map(intval(...), (array) $state['location_candidates']);
        $picked = $this->matchLocationChoice($candidates, $message);

        if ($picked !== null && (string) $state['query'] !== '') {
            $state['phase'] = 'searching';
            $state['location_id'] = $picked->id;

            return $this->runSearch($session, $state, (string) $state['query'], $picked);
        }

        // Not a pick: the reply goes through the normal intake as a
        // refinement. The open list stays valid until a search supersedes
        // or re-offers it — an unreadable message (a sticker, a stray row
        // id) must not kill the awaited tap.
        return $this->search($session, $state, $message);
    }

    /**
     * @param  list<int>  $candidates
     */
    protected function matchLocationChoice(array $candidates, InboundMessage $message): ?Location
    {
        if ($candidates === []) {
            return null;
        }

        $replyId = (string) $message->replyId;

        if (str_starts_with($replyId, self::LOCATION_ROW_PREFIX)) {
            $id = (int) Str::after($replyId, self::LOCATION_ROW_PREFIX);

            return in_array($id, $candidates, true) ? Location::find($id) : null;
        }

        $text = mb_strtolower(trim((string) $message->text));

        if ($text === '') {
            return null;
        }

        $byName = Location::query()->whereIn('id', $candidates)->get()
            ->filter(fn (Location $location): bool => mb_strtolower($location->name) === $text);

        return $byName->count() === 1 ? $byName->first() : null;
    }

    /**
     * Legacy «Искать шире» tap from a message sent before the catalog
     * handoff: re-runs the saved query one location level up. Expanding
     * is free: it is our own suggestion, so it never spends a
     * fruitless-search attempt. New dialogs never enter the expanding
     * phase — an empty subtree hands off to the catalog instead.
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
            return $this->offerMatches($session, $state, $query, $matches, $location);
        }

        if ($location->parent_id !== null) {
            return $this->offerWiderCatalog($session, $state, $query, $location);
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
     * a way back to the main dialog. The catalog CTA follows as its own
     * message (WhatsApp cannot mix reply buttons and a URL button) — an
     * empty выдача is exactly what browsing the full catalog fixes. No
     * prefill: this query just proved empty against the same matcher.
     */
    protected function sendDeadEnd(BotSession $session, string $text): void
    {
        $this->messenger->sendButtons(
            $session->contact,
            $text,
            [['id' => self::BUTTON_MENU, 'title' => self::BUTTON_MENU_TITLE]],
        );

        $this->sendCatalogCta(
            $session,
            'А ещё можно посмотреть весь каталог — вдруг подойдёт что-то из него.',
            self::CATALOG_BUTTON_DEAD_END,
        );
    }

    /**
     * The handoff to the web catalog: a personal signed link, sent with
     * every search outcome (a выдача or a dead end) and never with an
     * open question the bot is waiting on. Always a free session message
     * — every send happens in the turn of an inbound customer message,
     * so the 24-hour window is open by definition. A failure is logged
     * and swallowed: the CTA is an enhancement and must not break the
     * already-delivered outcome.
     */
    protected function sendCatalogCta(BotSession $session, string $text, string $button, ?string $query = null, ?Location $location = null): void
    {
        try {
            $this->messenger->sendCtaUrl(
                $session->contact,
                $text,
                $button,
                $this->links->catalogUrl($session->contact, $query, $location),
            );
        } catch (Throwable $e) {
            Log::warning('Failed to send the catalog CTA.', [
                'bot_session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function matchesExpandButton(InboundMessage $message): bool
    {
        return $message->replyId === self::BUTTON_EXPAND
            || mb_strtolower(trim((string) $message->text)) === mb_strtolower(self::BUTTON_EXPAND_TITLE);
    }

    /**
     * A second pick of the same listing while the earlier request is
     * still pending is deduplicated by the placer (the customer may have
     * already pressed «Выбрать» in the web catalog) — the supplier is
     * not pinged twice, the customer just hears the request is on its way.
     *
     * @param  array<string, mixed>  $state
     */
    protected function placeRequest(BotSession $session, array $state, Listing $listing): AiOutcome
    {
        $request = $this->placer->place($session->contact, $listing, (string) $state['query']);

        $this->messenger->sendText(
            $session->contact,
            sprintf(
                $request->wasRecentlyCreated
                    ? 'Заявка по варианту «%s» отправлена поставщику. Как только он ответит, мы сразу сообщим вам.'
                    : 'Заявка по варианту «%s» уже отправлена поставщику — ждём его ответа.',
                $listing->displayName() ?: 'объявление',
            ),
        );

        return AiOutcome::Completed;
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

        // Both the clamped row title (what the customer sees) and the full
        // unclamped one count: a title over the 24-char row limit is shown
        // truncated with an ellipsis, which cannot be typed back.
        /** @var Collection<int, Listing> $byTitle */
        $byTitle = Listing::query()->searchable()->whereIn('id', $offered)->get()
            ->filter(fn (Listing $listing): bool => in_array($text, [
                Str::lower($this->rowTitle($listing)),
                Str::lower($this->fullRowTitle($listing)),
            ], true));

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
        return WhatsappText::clamp($this->fullRowTitle($listing), self::ROW_TITLE_LIMIT);
    }

    protected function fullRowTitle(Listing $listing): string
    {
        return $listing->displayName() ?: 'Объявление №'.$listing->id;
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
     * @param  EloquentCollection<int, Location>  $candidates
     */
    protected function clarifyingQuestion(array $requirements, array $missing, EloquentCollection $candidates): string
    {
        // The extractor believes the place is settled, so its question
        // would miss the dictionary lookup failure — same wording as the
        // supplier collector for an unknown place. More namesakes than a
        // list can hold is its own case: the name IS in the dictionary,
        // so retyping it cannot help — only a bigger unit can.
        if ($missing[0] === 'location_unresolved') {
            return $candidates->count() > LocationResolver::MAX_CANDIDATES
                ? sprintf(
                    'Мест с названием «%s» в справочнике слишком много. Напишите точнее — вместе с областью или районом.',
                    $requirements['location'],
                )
                : sprintf(
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
            'location_candidates' => [],
            'location_id' => null,
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
