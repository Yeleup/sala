<?php

namespace App\Services\Ai;

use App\Ai\Agents\SearchQueryExtractionAgent;
use App\Enums\AiOperationType;
use App\Enums\AiOutcome;
use App\Enums\BotReplyKey;
use App\Enums\CustomerRequestStatus;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The customer branch of the AI module (docs/modules/ai-assistant.md):
 * collects what the customer needs and where over a short intake dialog
 * (typed or voice messages, transcribed upstream by ScenarioAiAssistant),
 * asking clarifying questions about the missing pieces, and only then
 * matches the settled query against published listings and hands the
 * results off to the personal web catalog (one message: the results
 * header with the «Все варианты» URL button, the query prefilled) — there
 * the chosen option becomes a customer request with a supplier
 * notification. Rows of legacy in-chat result
 * lists (sent before the catalog handoff) keep working until a new
 * search supersedes them. Equipment is never locked by a request.
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

    /**
     * How long a search stays open after its outcome (a выдача, «пусто»,
     * «посмотрите шире»). Past that, a text no longer refines the old query:
     * the block lets it go unread (AiOutcome::Reroute) and it is read as a
     * returning contact's message — hours later it is rarely about the old
     * query, and the model, which sees no time, kept taking a greeting for a
     * refinement and re-sending the same выдача.
     */
    public const int OUTCOME_TTL_MINUTES = 60;

    private const string ROW_ID_PREFIX = 'listing:';

    public const string LOCATION_ROW_PREFIX = 'search_location:';

    public const string LOCATION_LIST_BUTTON = 'Выбрать место';

    /** WhatsApp limits: list row title 24 chars, description 72, button 20. */
    private const int ROW_TITLE_LIMIT = 24;

    private const int ROW_DESCRIPTION_LIMIT = 72;

    /**
     * WhatsApp lists hold at most 10 rows: the location pick list shows
     * at most this many candidates, the last slot reserved for the
     * «В меню» exit row.
     */
    public const int MAX_OFFERED_ROWS = 9;

    /**
     * Legacy: the «Искать шире» button of messages sent before the
     * catalog handoff replaced it. New messages never carry it, but taps
     * on the old ones keep working (a free in-chat search one level up),
     * so the constants and the expanding phase stay handled.
     */
    public const string BUTTON_EXPAND = 'search_expand';

    public const string BUTTON_EXPAND_TITLE = 'Искать шире';

    /**
     * Releases the contact from the search back to the main dialog. New
     * search outcomes no longer carry it — they ride a single message with
     * the catalog URL button, and WhatsApp cannot add a reply button to
     * that — but questions, the place pick list, the outcome fallback and
     * the answer to a repeated query do, and taps on older outcome messages
     * keep working.
     */
    public const string BUTTON_MENU = 'search_to_menu';

    public const string BUTTON_MENU_TITLE = 'В меню';

    /**
     * The exit of an untouched search — one level up, to the menu the
     * branch hangs under (AiOutcome::Back). Shown only while nothing has
     * been written; see exitButton().
     */
    public const string BUTTON_BACK = 'search_back';

    public const string BUTTON_BACK_TITLE = 'Назад';

    /** WhatsApp caps URL-button titles at 20 characters. */
    public const string CATALOG_BUTTON_RESULTS = 'Все варианты';

    public const string CATALOG_BUTTON_DEAD_END = 'Открыть каталог';

    private const string QUERY_EXAMPLE = 'например: «кран 25 тонн, Шымкент»';

    /**
     * The way out of an outcome that leaves the search open (a выдача,
     * «пусто», «посмотрите шире»): such a message rides the catalog URL
     * button, WhatsApp cannot add «В меню» next to it, and without this line
     * nothing on screen said how to leave. Its fallback forms carry the
     * button itself, and the farewell closes the block, so neither says it.
     */
    public const string MENU_HINT = 'Чтобы вернуться в меню, напишите «меню».';

    /**
     * The customer's own words quoted in an outcome (the subject, a place
     * missing from the dictionary) are clamped to this length: the catalog
     * sentence and the menu hint end the message, so the 1024-char body
     * limit must cut the quote, never the sentences after it.
     */
    private const int QUOTED_INPUT_LIMIT = 200;

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
     * Enter the search. With a carried message — one the navigator moved
     * here from the menu — the block skips its invitation and answers by
     * what was already written: the invitation asks what the contact is
     * looking for, and they have just said it.
     *
     * A kind with nothing to find ends the block right here, whichever way
     * the contact came in: any query, carried or yet to be asked, could
     * only end empty. One honest message replaces the invitation — the
     * operator's own text too, since it invites a query nobody can answer —
     * and nothing else happens: no AI call, no fruitless attempt, no
     * catalog button (the catalog of that kind is just as empty). Checked
     * on entry only: a search already open keeps going.
     *
     * @param  array<string, mixed>  $node
     */
    public function start(BotSession $session, array $node, ?InboundMessage $carried = null): AiOutcome
    {
        $kind = ListingKind::fromNode($node['kind'] ?? null);

        if (! $this->matcher->hasListings($kind)) {
            // str_replace, not sprintf: the operator edits the text, and a
            // lone percent sign in it would make sprintf throw.
            $this->messenger->sendText(
                $session->contact,
                str_replace('%s', $kind->label(), $this->replyTexts->get(BotReplyKey::SearchSectionEmpty)),
            );

            return AiOutcome::Completed;
        }

        $session->state = ['kind' => $kind->value] + $this->defaultState();
        $session->save();

        if ($carried !== null) {
            return $this->resume($session, $node, $carried);
        }

        $this->messenger->sendButtons(
            $session->contact,
            trim((string) ($node['text'] ?? '')) ?: $this->searchGreeting($kind),
            $this->exitButton($session->state),
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

        // «Назад» — the untouched search's own exit, one level up to the
        // menu the branch hangs under. Once anything is written it can only
        // be an old button from an earlier message, and then it means what
        // «В меню» means: the search asks no confirmation, so it just ends.
        if ($this->matchesBackButton($message)) {
            return $this->hasProgress($state) ? AiOutcome::Menu : AiOutcome::Back;
        }

        // «В меню» — by button tap or its typed name — releases the contact
        // to the main dialog regardless of the phase. On an untouched search
        // the button is no longer shown (see exitButton()) — this branch
        // then serves a typed title or an older button only. The outcome
        // is Menu, not Completed: the menu is what they asked for, while a
        // search that ran to its own end leaves the dialog on its last line.
        if ($this->matchesMenuButton($message)) {
            return AiOutcome::Menu;
        }

        if ($state['phase'] === 'locating') {
            return $this->handleLocating($session, $state, $message, $node);
        }

        // Легаси: списки выдачи, отправленные до передачи выдачи каталогу,
        // остаются в чатах — их строки продолжают размещать заявку (или
        // честно сообщают об устаревании), пока новая выдача не сменит фазу.
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

                return $this->runSearch($session, $state, (string) $state['query'], countAttempt: false, rerun: true);
            }
        }

        if ($state['phase'] === 'expanding' && $this->matchesExpandButton($message)) {
            return $this->expandSearch($session, $state, $node);
        }

        return $this->search($session, $state, $message, $node);
    }

    /**
     * Whether the search went stale for this message: its last outcome
     * went out more than OUTCOME_TTL_MINUTES ago, nothing was asked since,
     * and the message is words — typed or spoken. The search then lets it
     * go unread (AiOutcome::Reroute) and resume() never sees it.
     *
     * Decided once per turn, by the AI entry point and before a voice is
     * transcribed: asked again after the transcription, the hour could run
     * out in between — and the engine would pay for the same voice twice.
     *
     * Only words go stale. A press — the catalog, «В меню», «Назад», a row
     * or button of an earlier message — answers what the bot showed, and a
     * typed button title is the press spelled out. Anything the search asked
     * and is waiting on — a clarifying question, a place list, a request to
     * write in words, the invitation repeated after a service question —
     * waits for its answer however late it comes: sending it stops the
     * clock. A state written before outcomes were timed has no outcome time
     * and reads as fresh.
     */
    public function hasGoneStale(BotSession $session, InboundMessage $message): bool
    {
        $state = is_array($session->state) ? $session->state : [];
        $outcomeAt = $state['outcome_at'] ?? null;

        if (! is_string($outcomeAt) || ($state['phase'] ?? 'searching') !== 'searching') {
            return false;
        }

        if ($message->isPress() || $this->matchesMenuButton($message) || $this->matchesBackButton($message)) {
            return false;
        }

        if (trim((string) $message->text) === '' && ! $message->isVoice()) {
            return false;
        }

        try {
            return Carbon::parse($outcomeAt)->addMinutes(self::OUTCOME_TTL_MINUTES)->isPast();
        } catch (Throwable) {
            return false;
        }
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
                $this->persist($session, $this->awaitingAnswer($state));
                $this->messenger->sendButtons(
                    $session->contact,
                    'Голосовое не расшифровалось — бывает. Напишите, пожалуйста, текстом: что нужно и в каком городе?',
                    $this->exitButton($state),
                );

                return AiOutcome::InProgress;
            }
        }

        if ($input === '') {
            $this->persist($session, $this->awaitingAnswer($state));
            $this->messenger->sendButtons(
                $session->contact,
                'Напишите, пожалуйста, текстом: что нужно и в каком городе?',
                $this->exitButton($state),
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

            return $this->runSearch($session, $state, implode(', ', $state['transcript']), rerun: true);
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
        // button, just spelled out instead of tapped — the engine carries
        // the contact to the main dialog, so no message goes out here. A
        // message not about the search at all (a greeting, a meaningless
        // string) leaves the same way: there is nothing in it to search by,
        // and refining with it only repeated the last outcome — a fruitless
        // attempt spent per greeting, or the same выдача paid for again.
        if ($intent === UserIntent::MenuRequested || $intent === UserIntent::OffTopic) {
            $state['transcript'] = array_slice($state['transcript'], 0, $intakeMark);
            $this->persist($session, $state);

            return AiOutcome::Menu;
        }

        // The free answer is bounded like every other exit of the block.
        // Past the limit the message walks the ordinary search path: it
        // stays in the transcript, feeds the requirements and spends a
        // clarification like any other, so the existing limits carry the
        // dialog to a search over whatever was collected.
        if ($intent === UserIntent::ServiceQuestion && $state['service_questions'] < self::MAX_SERVICE_QUESTIONS) {
            $state['transcript'] = array_slice($state['transcript'], 0, $intakeMark);
            $state['service_questions']++;
            // The step repeated below is a question too — after an outcome,
            // the block's invitation.
            $state = $this->awaitingAnswer($state);
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
            $state = $this->awaitingAnswer($state);
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
     * A search that asks again for exactly what the last one ran with is
     * not run again (see answerRepeatedSearch()) — whether the customer
     * worded it or picked the place from a list. $rerun is for the two
     * searches that run regardless: the provider-failure fallback keeps its
     * former behaviour, and the restart after a stale row refreshes a
     * выдача that went stale under the customer, not a repeated query.
     *
     * Every outcome is sent first and recorded after: a reply that did not
     * go out leaves the search where it stood, so the retry of the same
     * message answers it again — and a line the bot said just before (the
     * stale-row notice) can still open the outcome's own message.
     *
     * @param  array<string, mixed>  $state
     */
    protected function runSearch(BotSession $session, array $state, string $query, ?Location $location = null, bool $countAttempt = true, bool $rerun = false): AiOutcome
    {
        // A running search supersedes an open place pick list.
        $state['location_candidates'] = [];

        $location ??= $this->locations->detectInQuery($query);

        if (! $rerun && $this->repeatsLastSearch($state, $query, $location)) {
            return $this->answerRepeatedSearch($session, $state);
        }

        $matches = $this->matcher->match($query, $location, $this->kind($state), $this->matchFilters($state));

        if ($matches->isEmpty()) {
            // The restart after an honestly-stale row never spends a
            // fruitless attempt — our own выдача went stale, not the
            // customer's query.
            if ($countAttempt) {
                $state['attempts']++;
            }

            if ($state['attempts'] >= self::MAX_FRUITLESS_SEARCHES) {
                $this->sendCatalogCta(
                    $session,
                    'Подходящего сейчас не нашлось — так бывает, база пополняется каждый день. Загляните в каталог: вдруг что-то уже появилось.',
                    self::CATALOG_BUTTON_DEAD_END,
                    kind: $this->kind($state),
                );
                $this->persist($session, $state);

                return AiOutcome::Completed;
            }

            // The query named a place with nothing inside: hand off to the
            // catalog one level up instead of a dead «не нашлось».
            if ($location !== null && $location->parent_id !== null) {
                return $this->offerWiderCatalog($session, $state, $query, $location);
            }

            $this->sendDeadEnd(
                $session,
                sprintf('Пока по такому запросу пусто. Попробуйте сказать иначе — вид техники и город, %s.', self::QUERY_EXAMPLE),
                $this->kind($state),
            );
            $this->persist($session, $this->withOutcome($state, $query, $location, found: false));

            return AiOutcome::InProgress;
        }

        return $this->offerCatalogResults($session, $state, $query, $location);
    }

    /**
     * Whether the settled requirements are exactly what the last search
     * ran with — the same subject, place and travel filter. Such a search
     * would only return the same выдача (or the same «пусто», spending
     * another fruitless attempt on it): a greeting or a stray line the
     * model read as a refinement kept doing exactly that.
     *
     * @param  array<string, mixed>  $state
     */
    private function repeatsLastSearch(array $state, string $query, ?Location $location): bool
    {
        $last = $state['last_search'] ?? null;

        if (! is_array($last)) {
            return false;
        }

        foreach ($this->searchSignature($state, $query, $location) as $key => $value) {
            if (! array_key_exists($key, $last) || $last[$key] !== $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * The answer to a repeated query: one plain message — the outcome is
     * already in the chat, so neither the search nor its catalog link goes
     * out again and no fruitless attempt is spent. Not an outcome itself, so
     * it can carry «В меню»; the outcome clock keeps counting from the real
     * outcome, the one this message points back to.
     *
     * @param  array<string, mixed>  $state
     */
    private function answerRepeatedSearch(BotSession $session, array $state): AiOutcome
    {
        $this->messenger->sendButtons(
            $session->contact,
            ($state['last_search']['found'] ?? false)
                ? 'Варианты по этому запросу уже показаны выше. Чтобы поискать другое, назовите другую технику или другое место — или скажите иначе.'
                : 'По этому запросу уже искали — пока пусто. Попробуйте назвать другую технику или другое место — или скажите иначе.',
            [['id' => self::BUTTON_MENU, 'title' => self::BUTTON_MENU_TITLE]],
        );

        // A question or list asked since is answered: the answer led back
        // to the search that already ran, so the dialog is back after that
        // search's outcome — on its clock.
        $state = $this->closeOpenQuestion($state);
        $state['outcome_at'] = $state['last_search']['at'] ?? null;
        $this->persist($session, $state);

        return AiOutcome::InProgress;
    }

    /**
     * Record an outcome that leaves the search open: when it went out (the
     * stale clock, see hasGoneStale()) and what the search ran with (the
     * repeat guard, see repeatsLastSearch()). The dialog now waits for a
     * refinement after an outcome, whatever it waited for before.
     *
     * Called only once the outcome went out — by its catalog message or the
     * fallback. A turn whose outcome reached nobody fails before anything
     * is saved, and the webhook job retries it from the state before it: the
     * retry searches and sends the outcome instead of taking it for shown,
     * and neither the transcript nor a counter moves twice.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function withOutcome(array $state, string $query, ?Location $location, bool $found): array
    {
        $now = now()->toIso8601String();

        $state = $this->closeOpenQuestion($state);
        $state['outcome_at'] = $now;
        $state['last_search'] = $this->searchSignature($state, $query, $location) + ['found' => $found, 'at' => $now];

        return $state;
    }

    /**
     * Whatever the dialog asked before is answered: a clarifying question,
     * a place list, a legacy result list or «Искать шире» from older
     * messages. A search ran on the answer, so none of them is open any
     * more — the extractor no longer reads the old question as the bot's
     * last word, and a stale-clock check sees the dialog after an outcome.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function closeOpenQuestion(array $state): array
    {
        $state['phase'] = 'searching';
        $state['last_question'] = null;
        $state['location_candidates'] = [];
        $state['offered'] = [];
        $state['expand_location_id'] = null;

        return $state;
    }

    /**
     * The search asks something and waits for the answer — a clarifying
     * question, a place list, a request to write in words, the repeated
     * invitation. The dialog is no longer after an outcome, so the answer
     * does not go stale however late it comes (hasGoneStale()); the next
     * outcome, or an answer that leads back to the last search, starts the
     * clock again.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function awaitingAnswer(array $state): array
    {
        $state['outcome_at'] = null;

        return $state;
    }

    /**
     * What a search ran with, as far as the customer's words decide it:
     * the subject (the raw query when none was extracted), the place — the
     * dictionary node, or a named place the dictionary lacks, or none at
     * all, which is also what «место не важно» searches by — and the travel
     * filter. Letter case and spacing do not make a query different.
     *
     * @param  array<string, mixed>  $state
     * @return array{subject: string, location_id: int|null, unresolved_location: string|null, needs_travel: bool|null}
     */
    private function searchSignature(array $state, string $query, ?Location $location): array
    {
        $normalized = fn (?string $text): ?string => filled($text) ? Str::lower(Str::squish($text)) : null;

        return [
            'subject' => (string) $normalized(($state['subject'] ?? null) ?: $query),
            'location_id' => $location?->id,
            'unresolved_location' => $location === null ? $normalized($state['unresolved_location'] ?? null) : null,
            'needs_travel' => $state['needs_travel'] ?? null,
        ];
    }

    /**
     * The выдача lives in the web catalog: the chat gets one message — an
     * honest header (what matched and where) followed by where to look and
     * how to pick, with the «Все варианты» URL button into the catalog;
     * there the customer picks a listing and the «Выбрать» button places
     * the request. WhatsApp cannot add a reply button to a URL-button
     * message, so the outcome carries no «В меню» and says instead that
     * writing «меню» leaves (MENU_HINT): a worded menu request leaves, a
     * message not about the search leaves the same way, any other text
     * refines the search — within the hour (hasGoneStale()). The prefill
     * carries what this search ranked by, without duplication: with a
     * resolved place the link carries the subject alone plus the place as
     * the location filter; an unresolved place stays in the search text
     * (there it can still match the listings' location wording).
     *
     * @param  array<string, mixed>  $state
     */
    protected function offerCatalogResults(BotSession $session, array $state, string $query, ?Location $location = null): AiOutcome
    {
        $state['query'] = $query;

        $unresolvedLocation = $state['unresolved_location'] ?? null;
        $subject = ($state['subject'] ?? null) ?: $query;

        $header = filled($unresolvedLocation)
            ? sprintf('Место «%s» не нашлось в справочнике, поэтому подобрали варианты без учёта места.', $this->quoted($unresolvedLocation))
            : $this->resultsHeader($subject, $location);

        $this->sendCatalogCta(
            $session,
            $header.' Смотрите их в каталоге по кнопке ниже — запрос уже подставлен, там же поиск и фильтры. Выберите подходящий — заявка сразу уйдёт поставщику. '.self::MENU_HINT,
            self::CATALOG_BUTTON_RESULTS,
            $header.' Выбирайте в каталоге — заявка сразу уйдёт поставщику.',
            $location !== null ? $subject : $query,
            $location,
            $this->kind($state),
        );

        // Новая выдача гасит легаси-список прошлых сообщений: его строки
        // не должны выбираться после смены запроса. Открытый уточняющий
        // вопрос тоже закрыт — поиск по нему уже выполнен (withOutcome()).
        $this->persist($session, $this->withOutcome($state, $query, $location, found: true));

        return AiOutcome::InProgress;
    }

    /**
     * The honest results header: what matched and where. The count is
     * gone together with the chat list — the catalog shows every match,
     * so a chat-side number would describe nothing visible and disagree
     * with the catalog. Used only when the place is either unset or
     * resolved — an unresolved named place gets its own fixed honesty
     * string instead, see the caller.
     */
    private function resultsHeader(string $subject, ?Location $location): string
    {
        $header = sprintf('Нашлись варианты по запросу «%s»', $this->quoted($subject));

        if ($location !== null) {
            $header .= ' в '.$location->name;
        }

        return $header.'.';
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

        $state['query'] = $query;

        $this->sendCatalogCta(
            $session,
            sprintf('В «%s» пока пусто, но база пополняется каждый день. Посмотрите шире: в каталоге по кнопке ниже уже подставлены ваш запрос и «%s».', $location->name, $parent->name).' '.self::MENU_HINT,
            self::CATALOG_BUTTON_DEAD_END,
            sprintf('В «%s» пока пусто. Посмотрите шире: в каталоге уже подставлены ваш запрос и «%s».', $location->name, $parent->name),
            (($state['subject'] ?? null) ?: $query),
            $parent,
            $this->kind($state),
        );

        $this->persist($session, $this->withOutcome($state, $query, $location, found: false));

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
        $state = $this->awaitingAnswer($state);
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
     * Re-send whatever the assistant is waiting for. A legacy results
     * list is not resent — it is still visible in the chat, so a nudge is
     * enough and cheaper than a second interactive message. Before the
     * first clarifying question that is the block's greeting — the one
     * the operator writes in the scenario editor, not the built-in text.
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
            ?: $this->searchGreeting(ListingKind::fromNode($node['kind'] ?? null));

        // A re-sent question keeps «В меню»: a question means something was
        // said, and «Назад» under it would promise to undo an answer, which
        // the search cannot do. Only the greeting itself carries «Назад».
        $this->messenger->sendButtons(
            $session->contact,
            $question !== '' ? $question : $greeting,
            $question !== '' ? [['id' => self::BUTTON_MENU, 'title' => self::BUTTON_MENU_TITLE]] : $this->exitButton($state),
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

        // The pick settles the place: a pick that lands on the very search
        // that already ran is answered like any repeated query.
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
            return $this->offerCatalogResults($session, $state, $query, $location);
        }

        if ($location->parent_id !== null) {
            return $this->offerWiderCatalog($session, $state, $query, $location);
        }

        $this->sendDeadEnd(
            $session,
            sprintf('По всей стране пока пусто — шире уже некуда. Попробуйте сказать иначе, %s.', self::QUERY_EXAMPLE),
            $this->kind($state),
        );
        $this->persist($session, $this->withOutcome($state, $query, $location, found: false));

        return AiOutcome::InProgress;
    }

    /**
     * A fruitless search that still waits for the contact: the prompt to
     * rephrase and, in the same message, the «Открыть каталог» URL button —
     * an empty выдача is exactly what browsing the full catalog fixes. No
     * prefill: this query just proved empty against the same matcher. The
     * contact is not stuck without a button back: the text tells them to
     * write «меню» — a worded menu request leaves, any other text is the
     * rephrased query.
     */
    protected function sendDeadEnd(BotSession $session, string $text, ?ListingKind $kind = null): void
    {
        $this->sendCatalogCta(
            $session,
            $text.' Или загляните в каталог по кнопке ниже — там все объявления, база пополняется каждый день. '.self::MENU_HINT,
            self::CATALOG_BUTTON_DEAD_END,
            $text,
            kind: $kind,
        );
    }

    /**
     * The handoff to the web catalog: a personal signed link sent with
     * every search outcome (a выдача, a dead end, the farewell) and never
     * with an open question the bot is waiting on. The outcome and the
     * button travel as ONE message — session messages above the free
     * monthly quota are paid since 01.10.2026, and WhatsApp cannot put a
     * reply button next to a URL button, so the outcome carries no «В
     * меню» — the outcomes that leave the search open say in words how to
     * leave (MENU_HINT). Always a session message: every send happens in
     * the turn of an inbound customer message, so the 24-hour window is
     * open by definition.
     *
     * A failed link or send must not leave the customer without the
     * outcome: with a fallback text, the former form goes out instead —
     * the outcome without the catalog sentence, with the «В меню» button —
     * and a failure of that propagates like any outcome message. Only the
     * farewell has no fallback: the block ends either way.
     */
    protected function sendCatalogCta(BotSession $session, string $text, string $button, ?string $fallbackText = null, ?string $query = null, ?Location $location = null, ?ListingKind $kind = null): void
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

            if ($fallbackText !== null) {
                $this->messenger->sendButtons(
                    $session->contact,
                    $fallbackText,
                    [['id' => self::BUTTON_MENU, 'title' => self::BUTTON_MENU_TITLE]],
                );
            }
        }
    }

    /**
     * The customer's own words as quoted in an outcome message, clamped so
     * the catalog sentence after them survives the body limit.
     */
    private function quoted(string $text): string
    {
        return WhatsappText::clamp($text, self::QUOTED_INPUT_LIMIT);
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

    private function matchesBackButton(InboundMessage $message): bool
    {
        return $message->replyId === self::BUTTON_BACK
            || mb_strtolower(trim((string) $message->text)) === mb_strtolower(self::BUTTON_BACK_TITLE);
    }

    /**
     * Whether the search has moved past its first message: something was
     * said, a question was asked, a place list or a legacy result list is
     * open. Mirrors the collector's hasProgress() for the one decision it
     * gates here — which exit the waiting message carries.
     *
     * @param  array<string, mixed>  $state
     */
    private function hasProgress(array $state): bool
    {
        return ($state['transcript'] ?? []) !== []
            || ($state['query'] ?? null) !== null
            || ($state['last_question'] ?? null) !== null
            || ($state['location_candidates'] ?? []) !== []
            || ($state['offered'] ?? []) !== [];
    }

    /**
     * The exit an ordinary waiting message carries. An untouched search
     * offers «Назад» — one level up, to the menu the branch hangs under:
     * nothing is lost there, and «В меню» under the block's first message
     * read as «Далее» (audit 2026-09-14: 48 of 77 presses landed on a
     * block's invitation). Anything written switches it to «В меню».
     *
     * @param  array<string, mixed>  $state
     * @return list<array{id: string, title: string}>
     */
    private function exitButton(array $state): array
    {
        return $this->hasProgress($state)
            ? [['id' => self::BUTTON_MENU, 'title' => self::BUTTON_MENU_TITLE]]
            : [['id' => self::BUTTON_BACK, 'title' => self::BUTTON_BACK_TITLE]];
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

        // Уведомление провалилось прямо сейчас — заявка закрыта как «Без
        // ответа», повтор ничем не заблокирован. Честное признание вместо
        // ложного «ушла поставщику».
        $text = $request->wasRecentlyCreated && $request->status === CustomerRequestStatus::Expired
            ? 'Передать заявку по «%s» поставщику сейчас не получилось. Попробуйте, пожалуйста, выбрать вариант ещё раз позже.'
            : ($request->wasRecentlyCreated
                ? 'Заявка по «%s» ушла поставщику. Как только он ответит — сразу напишем.'
                : 'Заявка по «%s» уже у поставщика — ждём его ответа.');

        $this->messenger->sendText(
            $session->contact,
            sprintf($text, $listing->displayName() ?: 'объявление'),
        );

        return AiOutcome::Completed;
    }

    /**
     * A picked row of a legacy result list (by machine id), a typed text
     * that exactly matches the title of exactly one offered row, or its
     * ordinal position in that list — the scenario-wide convention that
     * typing a button's name equals pressing it (title matching takes
     * priority, so a listing titled with a digit stays reachable by its
     * title). Anything else is treated as a refined search query.
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
     * The row title of legacy result lists — still matched against typed
     * replies while such a list awaits a choice (matchChoice).
     */
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
            'outcome_at' => null,
            'last_search' => null,
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
