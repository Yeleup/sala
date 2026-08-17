<?php

namespace App\Services\Ai;

use App\Ai\Agents\SearchQueryExtractionAgent;
use App\Enums\AiOperationType;
use App\Enums\AiOutcome;
use App\Enums\BotReplyKey;
use App\Enums\ListingKind;
use App\Enums\UserIntent;
use App\Models\BotSession;
use App\Models\Listing;
use App\Models\Location;
use App\Services\Ai\Audit\AiAudit;
use App\Services\Bot\BotReplyTexts;
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

    /**
     * Questions about the service answered in a row before the assistant
     * stops treating a message as one. The abuse case is not a person
     * asking four times but a stuck classification: the question is pulled
     * out of the transcript, so an unchanged rephrasing gets classified the
     * same way forever, paying for an extraction call every turn.
     */
    private const int MAX_SERVICE_QUESTIONS = 3;

    private const string ROW_ID_PREFIX = 'listing:';

    public const string LOCATION_ROW_PREFIX = 'search_location:';

    public const string LOCATION_LIST_BUTTON = 'Выбрать место';

    /** WhatsApp limits: list row title 24 chars, description 72, button 20. */
    private const int ROW_TITLE_LIMIT = 24;

    private const int ROW_DESCRIPTION_LIMIT = 72;

    /**
     * WhatsApp lists hold at most 10 rows: every offered list (search
     * results, location candidates) shows at most this many entries, the
     * last slot reserved for the «В меню» exit row.
     */
    public const int MAX_OFFERED_ROWS = 9;

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
        private readonly BotReplyTexts $replyTexts,
    ) {}

    /**
     * @param  array<string, mixed>  $node
     */
    public function start(BotSession $session, array $node): AiOutcome
    {
        $kind = ListingKind::fromNode($node['kind'] ?? null);

        $session->state = ['kind' => $kind->value] + $this->defaultState();
        $session->save();

        $this->messenger->sendButtons(
            $session->contact,
            trim((string) ($node['text'] ?? '')) ?: $this->searchGreeting($kind),
            [['id' => self::BUTTON_MENU, 'title' => self::BUTTON_MENU_TITLE]],
        );

        return AiOutcome::InProgress;
    }

    /**
     * The built-in greeting of the search block per kind — what goes out
     * when the operator left the block's own text empty.
     */
    protected function searchGreeting(ListingKind $kind): string
    {
        return match ($kind) {
            ListingKind::Rental => 'Расскажите, что нужно и в каком городе — можно голосом. Например: «нужен кран 25 тонн, Шымкент».',
            ListingKind::Repair => 'Что случилось с техникой и в каком вы городе? Можно написать или наговорить голосом.',
            ListingKind::Driver => 'Какой водитель или машинист нужен и в каком городе? Можно написать или наговорить голосом.',
        };
    }

    /**
     * @param  array<string, mixed>  $node
     */
    public function resume(BotSession $session, array $node, InboundMessage $message): AiOutcome
    {
        $state = is_array($session->state) ? $session->state : [];
        $state += $this->defaultState();

        // «В меню» — by button tap or its typed name — releases the contact
        // to the main dialog regardless of the phase.
        if ($this->matchesMenuButton($message)) {
            return AiOutcome::Completed;
        }

        if ($state['phase'] === 'locating') {
            return $this->handleLocating($session, $state, $message, $node);
        }

        if ($state['phase'] === 'choosing') {
            $chosen = $this->matchChoice($state['offered'], $message);

            if ($chosen !== null) {
                return $this->placeRequest($session, $state, $chosen);
            }

            // The tapped row is still remembered as offered but the
            // listing behind it no longer passes searchable() — our own
            // выдача went stale, not the customer's query, so the restart
            // below does not spend a fruitless-search attempt.
            if ($this->isStaleRow($state['offered'], $message)) {
                $this->messenger->sendText($session->contact, 'Этот вариант уже сняли с публикации. Сейчас поищем свежие.');

                return $this->runSearch($session, $state, (string) $state['query'], countAttempt: false);
            }
        }

        if ($state['phase'] === 'expanding' && $this->matchesExpandButton($message)) {
            return $this->expandSearch($session, $state, $node);
        }

        return $this->search($session, $state, $message, $node);
    }

    /**
     * The intake step: accumulate the customer's messages, understand
     * what is needed and where, ask about the missing pieces (bounded by
     * the clarification limit), and run the search only once the
     * requirements are settled.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $node
     */
    protected function search(BotSession $session, array $state, InboundMessage $message, array $node): AiOutcome
    {
        $input = trim((string) $message->text);

        if ($input === '' && $message->isVoice()) {
            $input = trim((string) $message->transcription);

            // An unrecognized voice message (silence, download or AI
            // provider failure upstream) never spends a fruitless-search
            // attempt or a clarifying question.
            if ($input === '') {
                $this->persist($session, $state);
                $this->messenger->sendButtons(
                    $session->contact,
                    'Голосовое не расшифровалось — бывает. Напишите, пожалуйста, текстом: что нужно и в каком городе?',
                    [['id' => self::BUTTON_MENU, 'title' => self::BUTTON_MENU_TITLE]],
                );

                return AiOutcome::InProgress;
            }
        }

        if ($input === '') {
            $this->persist($session, $state);
            $this->messenger->sendButtons(
                $session->contact,
                'Напишите, пожалуйста, текстом: что нужно и в каком городе?',
                [['id' => self::BUTTON_MENU, 'title' => self::BUTTON_MENU_TITLE]],
            );

            return AiOutcome::InProgress;
        }

        // The transcript length before this message: a message the
        // extractor classifies as «not about the search» is rolled back.
        $intakeMark = count($state['transcript']);

        $state['transcript'][] = $input;
        $state['unresolved_location'] = null;

        $requirements = $this->extractRequirements($session, $state);

        // The AI provider is unavailable: degrade to searching the raw
        // text right away — the customer is never left without an answer.
        if ($requirements === null) {
            $state['subject'] = null;

            return $this->runSearch($session, $state, implode(', ', $state['transcript']));
        }

        $intent = UserIntent::fromExtraction($requirements['user_intent'] ?? null);

        // A refusal or an off-topic question is not a search requirement:
        // the message leaves the transcript and neither counter moves.
        if ($intent === UserIntent::Abandoned) {
            $state['transcript'] = array_slice($state['transcript'], 0, $intakeMark);
            $this->persist($session, $state);
            $this->messenger->sendText($session->contact, 'Хорошо, остановимся.');

            return AiOutcome::Completed;
        }

        // A worded request for the menu is the same exit as the «В меню»
        // button, just spelled out instead of tapped — the graph carries
        // the contact to the main dialog, so no message goes out here.
        if ($intent === UserIntent::MenuRequested) {
            $state['transcript'] = array_slice($state['transcript'], 0, $intakeMark);
            $this->persist($session, $state);

            return AiOutcome::Completed;
        }

        // The free answer is bounded like every other exit of the block.
        // Past the limit the message walks the ordinary search path: it
        // stays in the transcript, feeds the requirements and spends a
        // clarification like any other, so the existing limits carry the
        // dialog to a search over whatever was collected.
        if ($intent === UserIntent::ServiceQuestion && $state['service_questions'] < self::MAX_SERVICE_QUESTIONS) {
            $state['transcript'] = array_slice($state['transcript'], 0, $intakeMark);
            $state['service_questions']++;
            $this->persist($session, $state);
            $this->messenger->sendText($session->contact, $this->replyTexts->get(BotReplyKey::ServiceQuestion));
            $this->repeatCurrentStep($session, $state, $node);

            return AiOutcome::InProgress;
        }

        // Any message that is not a question about the service ends the
        // streak; the one that spent the limit is still such a question and
        // keeps it, so further ones keep walking the search path.
        if ($intent !== UserIntent::ServiceQuestion) {
            $state['service_questions'] = 0;
        }

        // The extracted subject on its own feeds the catalog link: with a
        // resolved place the place goes into the catalog's location
        // filter, so the search text must not duplicate it.
        $state['subject'] = filled($requirements['subject'] ?? null) ? (string) $requirements['subject'] : null;

        // The travel requirement (repair/driver branches only) survives
        // refinements like the picked place: a turn where the customer did
        // not repeat it (null) must not erase what was already said, while
        // an explicit change overrides.
        if (($requirements['needs_travel'] ?? null) !== null) {
            $state['needs_travel'] = (bool) $requirements['needs_travel'];
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
            $state['last_question'] = $this->clarifyingQuestion($requirements, $missing, $candidates);
            $this->persist($session, $state);
            $this->messenger->sendButtons(
                $session->contact,
                $state['last_question'],
                [['id' => self::BUTTON_MENU, 'title' => self::BUTTON_MENU_TITLE]],
            );

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
    protected function runSearch(BotSession $session, array $state, string $query, ?Location $location = null, bool $countAttempt = true): AiOutcome
    {
        // A running search supersedes an open place pick list.
        $state['location_candidates'] = [];

        $location ??= $this->locations->detectInQuery($query);
        $matches = $this->matcher->match($query, $location, $this->kind($state), $this->matchFilters($state));

        if ($matches->isEmpty()) {
            // The restart after an honestly-stale row never spends a
            // fruitless attempt — our own выдача went stale, not the
            // customer's query.
            if ($countAttempt) {
                $state['attempts']++;
            }

            if ($state['attempts'] >= self::MAX_FRUITLESS_SEARCHES) {
                $this->persist($session, $state);
                $this->sendCatalogCta(
                    $session,
                    'Подходящего сейчас не нашлось — так бывает, база пополняется каждый день. Загляните в каталог: вдруг что-то уже появилось.',
                    self::CATALOG_BUTTON_DEAD_END,
                    kind: $this->kind($state),
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
                sprintf('Пока по такому запросу пусто. Попробуйте сказать иначе — вид техники и город, %s.', self::QUERY_EXAMPLE),
                $this->kind($state),
            );

            return AiOutcome::InProgress;
        }

        return $this->offerMatches($session, $state, $query, $matches, $location);
    }

    /**
     * The catalog CTA rides with every результат: the chat list holds at
     * most 10 rows, the catalog shows everything. The prefill mirrors
     * what this search ranked by, without duplication: with a resolved
     * place the link carries the subject alone plus the place as the
     * location filter; an unresolved place stays in the search text
     * (there it can still match the listings' location wording).
     *
     * @param  array<string, mixed>  $state
     * @param  Collection<int, Listing>  $matches
     */
    protected function offerMatches(BotSession $session, array $state, string $query, Collection $matches, ?Location $location = null): AiOutcome
    {
        // WhatsApp lists hold at most 10 rows: at most 9 matches, the last
        // row reserved for the «В меню» exit.
        $matches = $matches->take(self::MAX_OFFERED_ROWS);

        $state['phase'] = 'choosing';
        $state['query'] = $query;
        $state['offered'] = $matches->pluck('id')->all();
        $state['expand_location_id'] = null;
        $this->persist($session, $state);

        $unresolvedLocation = $state['unresolved_location'] ?? null;

        $this->messenger->sendList(
            $session->contact,
            filled($unresolvedLocation)
                ? sprintf('Место «%s» не нашлось в справочнике, поэтому подобрали варианты без учёта места. Выберите подходящий — заявка сразу уйдёт поставщику.', $unresolvedLocation)
                : $this->resultsHeader($matches->count(), ($state['subject'] ?? null) ?: $query, $location),
            self::LIST_BUTTON,
            [
                ...$matches->map(fn (Listing $listing): array => $this->listRow($listing))->all(),
                ['id' => self::BUTTON_MENU, 'title' => self::BUTTON_MENU_TITLE],
            ],
        );

        $this->sendCatalogCta(
            $session,
            'Здесь — до 9 самых подходящих. Весь каталог с поиском и фильтрами по месту и категории — по кнопке ниже.',
            self::CATALOG_BUTTON_RESULTS,
            $location !== null ? (($state['subject'] ?? null) ?: $query) : $query,
            $location,
            $this->kind($state),
        );

        return AiOutcome::InProgress;
    }

    /**
     * The honest results header: how many matched, what for, and where —
     * replaces the old fixed «Вот что нашлось» with real numbers (used
     * only when the place is either unset or resolved — an unresolved
     * named place gets its own fixed honesty string instead, see the
     * caller).
     */
    private function resultsHeader(int $count, string $subject, ?Location $location): string
    {
        $header = ($count === 1 ? 'Нашёлся' : 'Нашлось')." {$count} ".$this->pluralVariants($count);
        $header .= sprintf(' по запросу «%s»', $subject);

        if ($location !== null) {
            $header .= ' в '.$location->name;
        }

        return $header.'. Выберите подходящий — заявка сразу уйдёт поставщику.';
    }

    /**
     * The Russian noun form for a results count — bounded by
     * MAX_OFFERED_ROWS (9), so the 11–14 exception of the full
     * pluralization rule never applies here.
     */
    private function pluralVariants(int $count): string
    {
        return match (true) {
            $count === 1 => 'вариант',
            $count >= 2 && $count <= 4 => 'варианта',
            default => 'вариантов',
        };
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

        // WhatsApp cannot mix reply buttons with a URL button: the «В
        // меню» exit rides its own message ahead of the catalog CTA,
        // mirroring sendDeadEnd.
        $this->messenger->sendButtons(
            $session->contact,
            sprintf('В «%s» пока пусто. Посмотрите шире: в каталоге уже подставлены ваш запрос и «%s».', $location->name, $parent->name),
            [['id' => self::BUTTON_MENU, 'title' => self::BUTTON_MENU_TITLE]],
        );

        $this->sendCatalogCta(
            $session,
            'Или загляните в каталог — там все объявления, база пополняется каждый день.',
            self::CATALOG_BUTTON_DEAD_END,
            (($state['subject'] ?? null) ?: $query),
            $parent,
            $this->kind($state),
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

        $this->sendLocationChoices($session, $candidates);

        return AiOutcome::InProgress;
    }

    /**
     * @param  EloquentCollection<int, Location>  $candidates
     */
    protected function sendLocationChoices(BotSession $session, EloquentCollection $candidates): void
    {
        // WhatsApp lists hold at most 10 rows: at most 9 candidates, the
        // last row reserved for the «В меню» exit. A candidate left out
        // is still pickable by typing its exact name (matchLocationChoice
        // matches against the full stored list, not just what is shown).
        $this->messenger->sendList(
            $session->contact,
            'Нашли несколько подходящих мест — уточните, в каком из них искать.',
            self::LOCATION_LIST_BUTTON,
            [
                ...$candidates
                    ->take(self::MAX_OFFERED_ROWS)
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
                ['id' => self::BUTTON_MENU, 'title' => self::BUTTON_MENU_TITLE],
            ],
        );
    }

    /**
     * Re-send whatever the assistant is waiting for. The results list is
     * not resent — it is still visible in the chat, so a nudge is enough
     * and cheaper than a second interactive message. Before the first
     * clarifying question that is the block's greeting — the one the
     * operator writes in the scenario editor, not the built-in text.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $node
     */
    protected function repeatCurrentStep(BotSession $session, array $state, array $node): void
    {
        $candidates = array_map(intval(...), (array) ($state['location_candidates'] ?? []));

        if ($state['phase'] === 'locating' && $candidates !== []) {
            $this->sendLocationChoices(
                $session,
                Location::query()->whereIn('id', $candidates)->orderBy('depth')->orderBy('id')->get(),
            );

            return;
        }

        if ($state['phase'] === 'choosing') {
            $this->messenger->sendButtons(
                $session->contact,
                'Выберите вариант из списка выше — или уточните запрос словами.',
                [['id' => self::BUTTON_MENU, 'title' => self::BUTTON_MENU_TITLE]],
            );

            return;
        }

        $question = trim((string) ($state['last_question'] ?? ''));
        $greeting = trim((string) ($node['text'] ?? ''))
            ?: sprintf('Что вам нужно и в каком городе, %s?', self::QUERY_EXAMPLE);

        $this->messenger->sendButtons(
            $session->contact,
            $question !== '' ? $question : $greeting,
            [['id' => self::BUTTON_MENU, 'title' => self::BUTTON_MENU_TITLE]],
        );
    }

    /**
     * The customer picks one of the same-named places — by the list row,
     * by typing a candidate's name matching exactly one of them, or by its
     * ordinal position in the current list (the scenario-wide convention).
     * Same-named candidates cannot be told apart by typed text, so such a
     * reply — like any other text — goes through the normal intake, which
     * re-offers the list.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $node
     */
    protected function handleLocating(BotSession $session, array $state, InboundMessage $message, array $node): AiOutcome
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
        return $this->search($session, $state, $message, $node);
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

        if ($byName->count() === 1) {
            return $byName->first();
        }

        // Ordinal position is 1-indexed over what the customer actually
        // sees: sendLocationChoices() renders at most MAX_OFFERED_ROWS
        // candidates, while $candidates here can hold up to
        // LocationResolver::MAX_CANDIDATES (10) — a hidden 10th candidate
        // stays reachable only by its exact typed name, matched above.
        $ordinal = $this->matchOrdinal(array_slice($candidates, 0, self::MAX_OFFERED_ROWS), $message);

        return $ordinal !== null ? Location::find($ordinal) : null;
    }

    /**
     * Legacy «Искать шире» tap from a message sent before the catalog
     * handoff: re-runs the saved query one location level up. Expanding
     * is free: it is our own suggestion, so it never spends a
     * fruitless-search attempt. New dialogs never enter the expanding
     * phase — an empty subtree hands off to the catalog instead.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $node
     */
    protected function expandSearch(BotSession $session, array $state, array $node): AiOutcome
    {
        $location = Location::find($state['expand_location_id']);
        $query = (string) $state['query'];

        if ($query === '') {
            return $this->search($session, $state, new InboundMessage(text: null), $node);
        }

        // The saved expansion point vanished: the query is already
        // settled, so re-run it without re-entering the intake.
        if ($location === null) {
            return $this->runSearch($session, $state, $query);
        }

        $matches = $this->matcher->match($query, $location, $this->kind($state), $this->matchFilters($state));

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
            sprintf('По всей стране пока пусто — шире уже некуда. Попробуйте сказать иначе, %s.', self::QUERY_EXAMPLE),
            $this->kind($state),
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
    protected function sendDeadEnd(BotSession $session, string $text, ?ListingKind $kind = null): void
    {
        $this->messenger->sendButtons(
            $session->contact,
            $text,
            [['id' => self::BUTTON_MENU, 'title' => self::BUTTON_MENU_TITLE]],
        );

        $this->sendCatalogCta(
            $session,
            'Или загляните в каталог — там все объявления, база пополняется каждый день.',
            self::CATALOG_BUTTON_DEAD_END,
            kind: $kind,
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
    protected function sendCatalogCta(BotSession $session, string $text, string $button, ?string $query = null, ?Location $location = null, ?ListingKind $kind = null): void
    {
        try {
            $this->messenger->sendCtaUrl(
                $session->contact,
                $text,
                $button,
                $this->links->catalogUrl($session->contact, $query, $location, $kind),
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
     * The «В меню» exit — by row/button id or its typed title, the
     * scenario-wide convention that typing a button's name equals
     * pressing it (matchesExpandButton, matchChoice).
     */
    private function matchesMenuButton(InboundMessage $message): bool
    {
        return $message->replyId === self::BUTTON_MENU
            || mb_strtolower(trim((string) $message->text)) === mb_strtolower(self::BUTTON_MENU_TITLE);
    }

    /**
     * Free-text digits «1»–«N» pick the N-th element of the given id list
     * (1-indexed) — the scenario-wide ordinal convention
     * (ScenarioDefinition::matchOption). Callers try title matching first,
     * so a row titled with a digit stays reachable by its title.
     *
     * @param  list<int>  $ids
     */
    private function matchOrdinal(array $ids, InboundMessage $message): ?int
    {
        $text = trim((string) $message->text);

        if ($text === '' || ! ctype_digit($text)) {
            return null;
        }

        $index = ((int) $text) - 1;

        return $ids[$index] ?? null;
    }

    /**
     * A tap on a row that is still remembered as offered, but whose
     * listing no longer passes searchable() — archived, expired, or
     * unpublished since the list went out. Honesty distinguishes this
     * from an ordinary miss: the customer's tap was valid when the list
     * was sent, our own выдача just went stale underneath it.
     *
     * @param  list<int>  $offered
     */
    private function isStaleRow(array $offered, InboundMessage $message): bool
    {
        $replyId = (string) $message->replyId;

        if (! str_starts_with($replyId, self::ROW_ID_PREFIX)) {
            return false;
        }

        $id = (int) Str::after($replyId, self::ROW_ID_PREFIX);

        return in_array($id, $offered, true) && ! Listing::query()->searchable()->whereKey($id)->exists();
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
                    ? 'Заявка по «%s» ушла поставщику. Как только он ответит — сразу напишем.'
                    : 'Заявка по «%s» уже у поставщика — ждём его ответа.',
                $listing->displayName() ?: 'объявление',
            ),
        );

        return AiOutcome::Completed;
    }

    /**
     * A picked list row (by machine id), a typed text that exactly matches
     * the title of exactly one offered row, or its ordinal position in the
     * current list — the scenario-wide convention that typing a button's
     * name equals pressing it (title matching takes priority, so a listing
     * titled with a digit stays reachable by its title). Anything else is
     * treated as a refined search query.
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

        if ($byTitle->count() === 1) {
            return $byTitle->first();
        }

        $ordinal = $this->matchOrdinal($offered, $message);

        return $ordinal !== null ? Listing::query()->searchable()->find($ordinal) : null;
    }

    /**
     * The list row speaks each kind's language: a rental shows the place
     * and the price, a master — his services and place, a driver — his
     * machines, the years of experience and place.
     *
     * @return array{id: string, title: string, description?: string}
     */
    protected function listRow(Listing $listing): array
    {
        $row = [
            'id' => self::ROW_ID_PREFIX.$listing->id,
            'title' => $this->rowTitle($listing),
        ];

        $description = match ($listing->kind) {
            ListingKind::Rental => implode(' · ', array_filter([$listing->locationLine(), $listing->price])),
            ListingKind::Repair => implode(' · ', array_filter([$listing->services, $listing->locationLine()])),
            ListingKind::Driver => implode(' · ', array_filter([
                $listing->machineCategories->pluck('name')->implode(', ') ?: null,
                $listing->experience_years !== null ? 'стаж '.$listing->experience_years.' л.' : null,
                $listing->locationLine(),
            ])),
        };

        if ($description !== '') {
            $row['description'] = WhatsappText::clamp($description, self::ROW_DESCRIPTION_LIMIT);
        }

        return $row;
    }

    protected function rowTitle(Listing $listing): string
    {
        return WhatsappText::clamp($this->fullRowTitle($listing), self::ROW_TITLE_LIMIT);
    }

    /**
     * A master and a driver go by their name — that is who the customer
     * picks; a rental keeps the listing's display name.
     */
    protected function fullRowTitle(Listing $listing): string
    {
        return ($listing->kind !== ListingKind::Rental && filled($listing->person_name) ? $listing->person_name : null)
            ?? $listing->displayName() ?: 'Объявление №'.$listing->id;
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
        $prompt = implode("\n", $state['transcript']);

        // A short reply («не важно», «любой») only reads against the
        // question it answers — the bot's side goes in as context.
        $botMessage = $this->currentBotMessageSummary($state);

        if ($botMessage !== null) {
            $prompt = "Последнее сообщение бота заказчику: {$botMessage}\n\nСообщения заказчика:\n{$prompt}";
        }

        try {
            return $this->audit->run(
                AiOperationType::SearchQueryExtraction,
                fn (): array => (new SearchQueryExtractionAgent($this->kind($state)))
                    ->prompt($prompt)
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
     * What the bot last sent, compressed for the extractor's context line.
     * Null when the dialog has no open question yet (the greeting).
     *
     * @param  array<string, mixed>  $state
     */
    protected function currentBotMessageSummary(array $state): ?string
    {
        if ($state['phase'] === 'choosing') {
            return 'показал список найденных вариантов и ждёт выбора или уточнения запроса';
        }

        if ($state['phase'] === 'locating') {
            return 'прислал список одноимённых мест и попросил выбрать нужное';
        }

        $question = trim((string) ($state['last_question'] ?? ''));

        return $question !== '' ? 'задал вопрос: «'.$question.'»' : null;
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
                    'Место «%s» в справочнике не нашлось. Напишите город, район или село поточнее.',
                    $requirements['location'],
                );
        }

        if (filled($requirements['clarifying_question'] ?? null)) {
            return (string) $requirements['clarifying_question'];
        }

        return $missing[0] === 'subject'
            ? 'Какая техника нужна?'
            : 'В каком городе или районе нужна техника?';
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
            'kind' => ListingKind::Rental->value,
            'phase' => 'searching',
            'attempts' => 0,
            'clarifications' => 0,
            'service_questions' => 0,
            'transcript' => [],
            'query' => null,
            'subject' => null,
            'needs_travel' => null,
            'offered' => [],
            'location_candidates' => [],
            'location_id' => null,
            'expand_location_id' => null,
            'unresolved_location' => null,
            'last_question' => null,
        ];
    }

    /**
     * The listing kind of this search dialog. Stored in the state at
     * start(); a session started before kinds existed falls back to rental.
     *
     * @param  array<string, mixed>  $state
     */
    protected function kind(array $state): ListingKind
    {
        return ListingKind::fromNode($state['kind'] ?? null);
    }

    /**
     * The structural filters for the matcher — only the pieces the
     * customer actually stated (null means «не сказал»); the matcher
     * applies them as hard conditions, not ranking signals.
     *
     * @param  array<string, mixed>  $state
     * @return array{needs_travel?: bool}
     */
    protected function matchFilters(array $state): array
    {
        return array_filter(
            ['needs_travel' => $state['needs_travel'] ?? null],
            fn (?bool $value): bool => $value !== null,
        );
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
