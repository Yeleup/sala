<?php

namespace App\Services\Ai;

use App\Ai\Agents\ListingExtractionAgent;
use App\Ai\Agents\LocationChoiceAgent;
use App\Enums\AiOperationType;
use App\Enums\AiOutcome;
use App\Enums\BotReplyKey;
use App\Enums\ListingMediaType;
use App\Enums\ListingOrigin;
use App\Enums\ListingType;
use App\Enums\UserIntent;
use App\Models\BotSession;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Listing;
use App\Models\ListingMedia;
use App\Models\Location;
use App\Services\Ai\Audit\AiAudit;
use App\Services\Bot\BotReplyTexts;
use App\Services\Bot\InboundMessage;
use App\Services\DereuMediaDownloader;
use App\Services\DereuMessenger;
use App\Services\Locations\LocationResolver;
use App\Support\WhatsappText;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\Files\Image;
use Throwable;

/**
 * Collects a supplier's listing over a WhatsApp sub-dialog: extracts
 * fields from free-form text, audio and photos, asks for what is missing
 * (bounded by a clarification limit), and confirms before submitting for
 * moderation. See docs/modules/ai-assistant.md.
 */
class SupplierListingCollector
{
    /**
     * Clarification attempts before giving up and handing the supplier a
     * CTA URL to fill the draft in manually (business rule: 2–3 attempts).
     */
    private const int MAX_CLARIFICATIONS = 3;

    /**
     * Unreadable messages in a row (sticker, caption-less photo, silent
     * audio) before the collector stops asking and hands over the web
     * form. Without it the «просьба описать словами» loop is endless:
     * such a message never spends a clarification attempt.
     */
    private const int MAX_UNREADABLE = 3;

    /**
     * How many times the same-named place list may go out before the
     * collector stops offering it — the third request for it falls through
     * to the ordinary missing-field path. Picking from the list spends no
     * clarification attempt, so a supplier who keeps ignoring it would
     * otherwise get it forever.
     */
    private const int MAX_LOCATION_LISTS = 2;

    /**
     * AI provider failures in a row before the collector stops asking to
     * repeat. Unlike the customer search, the collector cannot degrade to
     * raw text: listing fields do not come out of a message without the
     * model. Two silent turns is the point where the web form beats
     * another apology.
     */
    private const int MAX_PROVIDER_FAILURES = 2;

    /**
     * Photos attached to one extraction call — enough to recognize the
     * equipment without inflating the prompt.
     */
    private const int MAX_PHOTO_ATTACHMENTS = 5;

    /** @var list<string> */
    private const array REQUIRED_FIELDS = ['category', 'description', 'location_id', 'price'];

    public const string LOCATION_ROW_PREFIX = 'listing_location:';

    public const string BUTTON_SUBMIT = 'listing_submit';

    public const string BUTTON_EDIT = 'listing_edit';

    /**
     * Button titles must fit the WhatsApp 20-character limit, or Meta
     * rejects the whole message asynchronously and the bot goes silent.
     */
    public const string BUTTON_SUBMIT_TITLE = 'Да, отправить';

    public const string BUTTON_EDIT_TITLE = 'Исправить';

    public function __construct(
        private readonly DereuMessenger $messenger,
        private readonly DereuMediaDownloader $mediaDownloader,
        private readonly CtaLinkBuilder $cta,
        private readonly AiAudit $audit,
        private readonly LocationResolver $locations,
        private readonly BotReplyTexts $replyTexts,
    ) {}

    /**
     * @param  array<string, mixed>  $node
     */
    public function start(BotSession $session, array $node): AiOutcome
    {
        $session->state = [
            'phase' => 'collecting',
            'attempts' => 0,
            'unreadable' => 0,
            'location_lists' => 0,
            'provider_failures' => 0,
            'transcript' => [],
            'fields' => [],
            'draft_id' => null,
            'listing_type' => $node['listing_type'] ?? null,
            'picked_location_id' => null,
            'picked_location_wording' => null,
            'declined_location_wording' => null,
            'last_question' => null,
        ];
        $session->save();

        $this->messenger->sendText(
            $session->contact,
            trim((string) ($node['text'] ?? '')) ?: 'Расскажите, что вы предлагаете: пришлите фото, голосовое или напишите текстом — что это, в каком городе и по какой цене.',
        );

        return AiOutcome::InProgress;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    public function resume(BotSession $session, array $node, InboundMessage $message): AiOutcome
    {
        $state = $this->normalizeState($session, $node);

        if ($state['phase'] === 'confirming') {
            return $this->handleConfirmation($session, $state, $message);
        }

        if ($state['phase'] === 'locating') {
            return $this->handleLocating($session, $state, $message);
        }

        return $this->handleCollecting($session, $state, $message);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function handleCollecting(BotSession $session, array $state, InboundMessage $message): AiOutcome
    {
        // The transcript length before intake: a message the extractor
        // classifies as «not about the listing» is rolled back out of it.
        $intakeMark = count($state['transcript']);

        // An unreadable message (sticker, empty caption, silent audio) never
        // consumes a clarification attempt — the bot just asks to rephrase.
        // But it is not free forever: a run of MAX_UNREADABLE in a row hands
        // the supplier over to the web form, the same dead end as a spent
        // clarification limit — otherwise the «опишите словами» loop never
        // ends for a message the model never even sees.
        if (! $this->intake($session, $state, $message)) {
            $state['unreadable']++;

            if ($state['unreadable'] >= self::MAX_UNREADABLE) {
                return $this->handOffToWebForm($session, $state);
            }

            $this->persist($session, $state);
            $this->messenger->sendText(
                $session->contact,
                'Не удалось разобрать сообщение. Опишите технику или услугу текстом, голосом или фото.',
            );

            return AiOutcome::InProgress;
        }

        $state['unreadable'] = 0;

        $fields = $this->extract($session, $state);

        if ($fields === null) {
            $state['provider_failures']++;

            if ($state['provider_failures'] >= self::MAX_PROVIDER_FAILURES) {
                return $this->handOffToWebForm($session, $state);
            }

            $this->persist($session, $state);
            $this->messenger->sendText(
                $session->contact,
                'Не получилось обработать сообщение. Повторите его, пожалуйста, ещё раз.',
            );

            return AiOutcome::InProgress;
        }

        $state['provider_failures'] = 0;

        // Nothing the supplier said reached the model on this turn: a
        // caption-less photo leaves the transcript untouched, so what got
        // classified was our own synthetic prompt, not their words. There is
        // nothing to classify — the turn is ordinary listing data, and a
        // confident «abandoned» would end the block on a silent photo.
        $intent = count($state['transcript']) === $intakeMark
            ? UserIntent::Task
            : UserIntent::fromExtraction($fields['user_intent'] ?? null);

        // A refusal or a question about the service is not listing data:
        // the message leaves the transcript and the fields stay as they
        // were, so «я передумал» or «это платно?» never ends up in the
        // saved description.
        if ($intent !== UserIntent::Task) {
            $state['transcript'] = array_slice($state['transcript'], 0, $intakeMark);

            return $intent === UserIntent::Abandoned
                ? $this->abandon($session, $state)
                : $this->answerServiceQuestion($session, $state);
        }

        $state['fields'] = $fields;

        return $this->advance($session, $state);
    }

    /**
     * Decide the next step from the collected fields: confirm, ask to pick
     * one of the matching dictionary locations, clarify, or hand off to the
     * web form once the clarification limit is spent.
     *
     * @param  array<string, mixed>  $state
     */
    private function advance(BotSession $session, array $state): AiOutcome
    {
        $missing = $this->missingFields($state['fields'], $state);

        if ($missing === []) {
            $draft = $this->ensureDraft($session, $state);
            $draft->update($this->listingAttributes($state));
            $state['phase'] = 'confirming';
            $this->persist($session, $state);
            $this->sendConfirmation($session, $state['fields']);

            return AiOutcome::InProgress;
        }

        // Several dictionary places match the named location: picking from
        // the list is not a clarification attempt.
        $candidates = array_map(intval(...), (array) ($state['fields']['location_candidates'] ?? []));

        if (in_array('location_id', $missing, true) && $candidates !== []) {
            // The supplier's own messages may already say which of the
            // namesakes it is («Абайский район» plus «Карагандинская
            // область» earlier in the dialog): asking then is a wasted
            // round trip. The pick is recorded exactly as a manual one, so
            // later turns reuse it instead of calling the model again.
            $chosen = $this->chooseLocation($session, $state, $candidates);

            if ($chosen !== null) {
                $state['picked_location_id'] = $chosen->id;
                $state['picked_location_wording'] = $this->locationWording((string) ($state['fields']['location'] ?? ''));
                $state['location_lists'] = 0;

                $state['fields']['location_id'] = $chosen->id;
                $state['fields']['location'] = $chosen->name;
                $state['fields']['location_candidates'] = [];

                // The place is settled, so this branch cannot be taken
                // again — the re-entry is bounded to one level.
                return $this->advance($session, $state);
            }

            // The list goes out a bounded number of times: a supplier who
            // keeps not picking falls through to the ordinary missing-field
            // path, which spends attempts and ends at the web form.
            if ($state['location_lists'] < self::MAX_LOCATION_LISTS) {
                $state['location_lists']++;
                $state['phase'] = 'locating';
                $this->persist($session, $state);
                $this->sendLocationChoices($session, $candidates);

                return AiOutcome::InProgress;
            }
        }

        if ($state['attempts'] >= self::MAX_CLARIFICATIONS) {
            return $this->handOffToWebForm($session, $state);
        }

        $state['attempts']++;
        $state['phase'] = 'collecting';
        $state['last_question'] = $this->clarificationQuestion($state['fields'], $missing);
        $this->persist($session, $state);
        $this->messenger->sendText($session->contact, $state['last_question']);

        return AiOutcome::InProgress;
    }

    /**
     * Save whatever was collected and let the supplier finish in the web
     * form. Shared by every dead end that must not loop: the clarification
     * limit, an unreadable-message streak, an unavailable AI provider.
     *
     * @param  array<string, mixed>  $state
     */
    private function handOffToWebForm(BotSession $session, array $state): AiOutcome
    {
        $draft = $this->ensureDraft($session, $state);
        $draft->update($this->listingAttributes($state));
        $this->persist($session, $state);
        $this->messenger->sendCtaUrl(
            $session->contact,
            'Не получилось собрать все данные из переписки. Откройте форму и заполните объявление вручную.',
            'Заполнить вручную',
            $this->cta->editUrl($draft),
        );

        return AiOutcome::Completed;
    }

    /**
     * An explicit refusal releases the supplier through the block's own
     * «continue» output. Whatever was collected is kept as a draft, but no
     * CTA to the web form goes out: the person just said they do not want
     * to continue, and a link would be nagging.
     *
     * @param  array<string, mixed>  $state
     */
    private function abandon(BotSession $session, array $state): AiOutcome
    {
        $attributes = $this->listingAttributes($state);

        // The type is always set, so it alone does not count as content;
        // an existing draft does — it may already hold photos or audio.
        $hasContent = $state['draft_id'] !== null
            || collect($attributes)->forget('type')->contains(fn (mixed $value): bool => filled($value));

        if (! $hasContent) {
            $this->persist($session, $state);
            $this->messenger->sendText($session->contact, 'Хорошо, остановимся.');

            return AiOutcome::Completed;
        }

        $draft = $this->ensureDraft($session, $state);
        $draft->update($attributes);
        $this->persist($session, $state);
        $this->messenger->sendText(
            $session->contact,
            'Хорошо, остановимся. Черновик объявления сохранили — он в вашем кабинете.',
        );

        return AiOutcome::Completed;
    }

    /**
     * A question about the service is answered with the operator's own
     * built-in reply and costs nothing: no clarification attempt, and the
     * message stays out of the listing data. The bot then repeats whatever
     * it was waiting for, so the dialog does not stall on an open question.
     *
     * @param  array<string, mixed>  $state
     */
    private function answerServiceQuestion(BotSession $session, array $state): AiOutcome
    {
        $this->persist($session, $state);
        $this->messenger->sendText($session->contact, $this->replyTexts->get(BotReplyKey::ServiceQuestion));
        $this->repeatCurrentStep($session, $state);

        return AiOutcome::InProgress;
    }

    /**
     * Re-send whatever the collector is waiting for at this phase.
     *
     * @param  array<string, mixed>  $state
     */
    private function repeatCurrentStep(BotSession $session, array $state): void
    {
        if ($state['phase'] === 'confirming') {
            $this->sendConfirmation($session, $state['fields']);

            return;
        }

        if ($state['phase'] === 'locating') {
            $this->sendLocationChoices(
                $session,
                array_map(intval(...), (array) ($state['fields']['location_candidates'] ?? [])),
            );

            return;
        }

        $question = trim((string) ($state['last_question'] ?? ''));

        $this->messenger->sendText(
            $session->contact,
            $question !== ''
                ? $question
                : 'Расскажите, что вы предлагаете: что это, в каком городе и по какой цене.',
        );
    }

    /**
     * The supplier picks one of the matching dictionary locations — by the
     * list row or by typing a row's title (the scenario-wide convention).
     * Any other reply is treated as further details.
     *
     * @param  array<string, mixed>  $state
     */
    private function handleLocating(BotSession $session, array $state, InboundMessage $message): AiOutcome
    {
        $candidates = array_map(intval(...), (array) ($state['fields']['location_candidates'] ?? []));
        $picked = $this->matchLocationChoice($candidates, $message);

        if ($picked !== null) {
            // The wording that produced this list is captured before the
            // node name overwrites it: the pick holds on later turns only
            // while the extractor keeps naming the place the same way.
            $state['picked_location_id'] = $picked->id;
            $state['picked_location_wording'] = $this->locationWording((string) ($state['fields']['location'] ?? ''));
            $state['location_lists'] = 0;

            $state['fields']['location_id'] = $picked->id;
            $state['fields']['location'] = $picked->name;
            $state['fields']['location_candidates'] = [];
            $state['phase'] = 'collecting';

            return $this->advance($session, $state);
        }

        return $this->handleCollecting($session, $state, $message);
    }

    /**
     * The one of the same-named dictionary places the supplier's messages
     * point at, or null when the accumulated input does not settle it —
     * then the pick list goes out as before. Candidates carry their full
     * ancestor chains, so «Абайский район» in Karaganda is told from the
     * one in Shymkent by what the supplier said around the name.
     *
     * An unavailable model, or an answer that is not one of the offered
     * ids, costs nothing: the dialog just falls back to asking.
     *
     * @param  array<string, mixed>  $state
     * @param  list<int>  $candidates
     */
    private function chooseLocation(BotSession $session, array &$state, array $candidates): ?Location
    {
        // Nothing was said in words (photos only): there is no evidence to
        // choose by, and a guess would bind a foreign region silently.
        if ($state['transcript'] === [] || count($candidates) < 2) {
            return null;
        }

        // The same wording was already put to the model and it did not
        // settle it. The supplier now owns the choice through the list, and
        // re-asking on every further message would just repeat a refusal at
        // full price.
        $wording = $this->locationWording((string) ($state['fields']['location'] ?? ''));

        if ($wording === ($state['declined_location_wording'] ?? null)) {
            return null;
        }

        try {
            $places = Location::query()->whereIn('id', $candidates)->orderBy('depth')->orderBy('id')->get();

            if ($places->count() < 2) {
                return null;
            }

            $chains = Location::chainsFor($places);

            $labels = $places
                ->mapWithKeys(fn (Location $place): array => [
                    $place->id => trim($place->name.', '.($chains[$place->id] ?? ''), ', '),
                ])
                ->all();

            $choice = $this->audit->run(
                AiOperationType::LocationDisambiguation,
                fn (): array => (new LocationChoiceAgent($labels))
                    ->prompt(implode("\n", $state['transcript']))
                    ->toArray(),
                [
                    'contact_id' => $session->contact_id,
                    'bot_session_id' => $session->id,
                    'listing_id' => $state['draft_id'],
                ],
            );
        } catch (Throwable $e) {
            Log::warning('Location disambiguation failed; falling back to the pick list.', [
                'bot_session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $chosen = $places->firstWhere('id', (int) ($choice['location_id'] ?? 0));

        if ($chosen === null) {
            $state['declined_location_wording'] = $wording;
        }

        return $chosen;
    }

    /**
     * @param  list<int>  $candidates
     */
    private function matchLocationChoice(array $candidates, InboundMessage $message): ?Location
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
     * @param  list<int>  $candidates
     */
    private function sendLocationChoices(BotSession $session, array $candidates): void
    {
        $rows = Location::query()
            ->whereIn('id', $candidates)
            ->orderBy('depth')
            ->orderBy('id')
            ->get()
            ->map(fn (Location $location): array => array_filter([
                'id' => self::LOCATION_ROW_PREFIX.$location->id,
                'title' => WhatsappText::clamp($location->name, 24),
                'description' => WhatsappText::clamp(
                    $location->ancestors()->sortByDesc('depth')->pluck('name')->implode(', '),
                    72,
                ) ?: null,
            ]))
            ->values()
            ->all();

        $this->messenger->sendList(
            $session->contact,
            'Нашли несколько подходящих мест — уточните, какое из них ваше.',
            'Выбрать место',
            $rows,
        );
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function handleConfirmation(BotSession $session, array $state, InboundMessage $message): AiOutcome
    {
        $draft = $state['draft_id'] !== null ? Listing::find($state['draft_id']) : null;

        if ($this->matchesButton($message, self::BUTTON_SUBMIT, self::BUTTON_SUBMIT_TITLE)) {
            $draft?->submitForModeration();
            $this->messenger->sendText($session->contact, 'Спасибо! Объявление отправлено на проверку модератору.');

            return AiOutcome::Completed;
        }

        if ($this->matchesButton($message, self::BUTTON_EDIT, self::BUTTON_EDIT_TITLE)) {
            if ($draft !== null) {
                $this->messenger->sendCtaUrl(
                    $session->contact,
                    'Откройте форму, чтобы проверить и поправить объявление.',
                    'Открыть объявление',
                    $this->cta->editUrl($draft),
                );
            }

            return AiOutcome::Completed;
        }

        // Anything else during confirmation is treated as more details:
        // re-collect, re-extract and confirm again.
        return $this->handleCollecting($session, $state, $message);
    }

    /**
     * Pull usable content out of the message into the transcript and store
     * photos / voice messages on the draft. Returns whether the message
     * carried anything the extractor can work with.
     *
     * @param  array<string, mixed>  $state
     */
    private function intake(BotSession $session, array &$state, InboundMessage $message): bool
    {
        $gotMedia = $message->hasMedia() && $this->intakeMedia($session, $state, $message);

        $text = trim((string) $message->text);

        if ($text !== '') {
            $state['transcript'][] = $text;

            return true;
        }

        return $gotMedia;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function intakeMedia(BotSession $session, array &$state, InboundMessage $message): bool
    {
        // Voice arrives already downloaded and transcribed by the AI entry
        // point (ScenarioAiAssistant); unresolved voice is unreadable — the
        // bot asks to rephrase without spending a clarification attempt.
        if ($message->mediaType === ListingMediaType::Audio) {
            if ($message->voiceContents === null) {
                return false;
            }

            $draft = $this->ensureDraft($session, $state);
            $transcription = (string) $message->transcription;

            $path = "listings/{$draft->id}/audio/".uniqid('', true).'.ogg';
            Storage::disk('public')->put($path, $message->voiceContents);

            ListingMedia::create([
                'listing_id' => $draft->id,
                'type' => ListingMediaType::Audio,
                'path' => $path,
                'transcription' => $transcription,
            ]);

            if ($transcription !== '') {
                $state['transcript'][] = $transcription;

                return true;
            }

            return false;
        }

        $download = $this->mediaDownloader->download((string) $message->mediaId);
        $draft = $this->ensureDraft($session, $state);

        $path = "listings/{$draft->id}/photos/".uniqid('', true).'.jpg';
        Storage::disk('public')->put($path, $download['contents']);

        ListingMedia::create([
            'listing_id' => $draft->id,
            'type' => ListingMediaType::Photo,
            'path' => $path,
        ]);

        return true;
    }

    /**
     * Run the extractor over the whole accumulated transcript, attaching
     * the draft's photos so the model reads the pictures themselves, not
     * only their captions.
     *
     * Null when the AI provider is unavailable — the caller then asks to
     * repeat and, on the second failure in a row, hands over the web form.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>|null
     */
    private function extract(BotSession $session, array $state): ?array
    {
        $expectedType = ListingType::tryFrom((string) ($state['listing_type'] ?? ''));

        // A branch with a fixed type offers only categories of that type;
        // the auto branch offers the whole dictionary.
        $categories = Category::query()
            ->when($expectedType !== null, fn ($query) => $query->where('type', $expectedType))
            ->orderBy('name')
            ->get();

        // Services carry no brand, so the service branch never sees the
        // brand dictionary at all.
        $brands = $expectedType === ListingType::Service
            ? new Collection
            : Brand::query()->orderBy('name')->get();

        $prompt = $state['transcript'] !== []
            ? implode("\n", $state['transcript'])
            : 'Поставщик прислал только фотографии — извлеки из них, что сможешь.';

        try {
            $fields = $this->audit->run(
                AiOperationType::ListingExtraction,
                fn (): array => (new ListingExtractionAgent($expectedType, $categories->pluck('name')->all(), $brands->pluck('name')->all()))
                    ->prompt($prompt, attachments: $this->photoAttachments($state))
                    ->toArray(),
                [
                    'contact_id' => $session->contact_id,
                    'bot_session_id' => $session->id,
                    'listing_id' => $state['draft_id'],
                ],
            );
        } catch (Throwable $e) {
            Log::warning('Listing extraction failed; the collector asks to repeat.', [
                'bot_session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $category = $this->canonicalCategory($fields['category'] ?? null, $categories);
        $fields['category'] = $category?->name;

        // The dictionary types the category, and the category types the
        // listing: a resolved category fixes the type even when the model
        // could not tell it (or contradicted the dictionary).
        if ($category !== null) {
            $fields['type'] = $category->type->value;
        }

        // The brand check runs after the type is settled: a category that
        // resolved the listing into a service drops the brand with it.
        $fields['brand'] = ($fields['type'] ?? null) === ListingType::Service->value
            ? null
            : $this->canonicalBrand($fields['brand'] ?? null, $brands)?->name;

        return $this->resolveLocation($fields, $state);
    }

    /**
     * The KATO dictionary is the only source of truth for the location:
     * the extracted wording either resolves to exactly one node, or keeps
     * a short candidate list for the supplier to pick from, or stays
     * unresolved and gets asked again. A place the supplier already picked
     * from that list holds across re-extractions (the fields are rebuilt
     * from scratch each turn) — the same list is never asked twice,
     * mirroring the customer search. The pick holds only while the
     * extractor keeps naming the place the same way: same-named places
     * share a search key («Абайская г.а.» resolves into the same candidate
     * set as «Абайский район»), so a changed wording is a correction and
     * reopens the list instead of silently keeping the old pick.
     *
     * @param  array<string, mixed>  $fields
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function resolveLocation(array $fields, array $state): array
    {
        $fields['location_id'] = null;
        $fields['location_candidates'] = [];
        $fields['location_overflow'] = false;

        if (blank($fields['location'] ?? null)) {
            return $fields;
        }

        $candidates = $this->locations->resolve((string) $fields['location']);

        // More namesakes than a WhatsApp list holds: cut to their biggest
        // disputed level, the same way the customer search does —
        // «Абайский район» offers its three districts, not twelve nodes.
        if ($candidates->count() > LocationResolver::MAX_CANDIDATES) {
            $candidates = $this->locations->placeCandidates((string) $fields['location']);
            $fields['location_overflow'] = $candidates->count() > LocationResolver::MAX_CANDIDATES;
        }

        // The supplier already picked one of these same-named places from
        // the list earlier in the dialog: the pick wins over the ambiguity.
        $picked = $candidates->count() > 1
                && $this->locationWording((string) $fields['location']) === ($state['picked_location_wording'] ?? null)
            ? $candidates->firstWhere('id', $state['picked_location_id'] ?? null)
            : null;

        if ($candidates->count() === 1) {
            $fields['location_id'] = $candidates->first()->id;
            $fields['location'] = $candidates->first()->name;
        } elseif ($picked !== null) {
            $fields['location_id'] = $picked->id;
            $fields['location'] = $picked->name;
            $fields['location_overflow'] = false;
        } elseif ($candidates->count() > 1 && $candidates->count() <= LocationResolver::MAX_CANDIDATES) {
            $fields['location_candidates'] = $candidates->pluck('id')->all();
        }

        return $fields;
    }

    /**
     * The category dictionary is the only source of truth: a value the
     * extractor returned is kept only when it matches an offered category
     * (the schema enum already enforces this — the lookup is a safety net),
     * normalized to the dictionary spelling.
     *
     * @param  Collection<int, Category>  $categories
     */
    private function canonicalCategory(mixed $name, Collection $categories): ?Category
    {
        if (blank($name) || ! is_string($name)) {
            return null;
        }

        $needle = mb_strtolower(trim($name));

        return $categories->first(
            fn (Category $category): bool => mb_strtolower($category->name) === $needle,
        );
    }

    /**
     * The brand dictionary is the only source of truth: a value the
     * extractor returned is kept only when it matches an offered brand
     * (the schema enum already enforces this — the lookup is a safety net),
     * normalized to the dictionary spelling. Unlike the category, a
     * dropped brand is never asked about — the field is optional.
     *
     * @param  Collection<int, Brand>  $brands
     */
    private function canonicalBrand(mixed $name, Collection $brands): ?Brand
    {
        if (blank($name) || ! is_string($name)) {
            return null;
        }

        $needle = mb_strtolower(trim($name));

        return $brands->first(
            fn (Brand $brand): bool => mb_strtolower($brand->name) === $needle,
        );
    }

    /**
     * @param  array<string, mixed>  $state
     * @return list<Image>
     */
    private function photoAttachments(array $state): array
    {
        if ($state['draft_id'] === null) {
            return [];
        }

        return Listing::find($state['draft_id'])
            ?->photos()->latest()->take(self::MAX_PHOTO_ATTACHMENTS)->get()
            ->map(fn (ListingMedia $photo): Image => Image::fromStorage($photo->path, $photo->disk))
            ->all() ?? [];
    }

    /**
     * The type joins the required fields when the branch leaves it to the
     * AI («Определять автоматически») — a listing must never silently
     * default to «техника».
     *
     * @param  array<string, mixed>  $fields
     * @param  array<string, mixed>  $state
     * @return list<string>
     */
    private function missingFields(array $fields, array $state): array
    {
        $missing = array_values(array_filter(
            self::REQUIRED_FIELDS,
            fn (string $field): bool => blank($fields[$field] ?? null),
        ));

        if (blank($state['listing_type']) && blank($fields['type'] ?? null)) {
            array_unshift($missing, 'type');
        }

        return $missing;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function ensureDraft(BotSession $session, array &$state): Listing
    {
        if ($state['draft_id'] !== null) {
            $draft = Listing::find($state['draft_id']);

            if ($draft !== null) {
                return $draft;
            }
        }

        $draft = Listing::create([
            'contact_id' => $session->contact_id,
            'origin' => ListingOrigin::Chat,
            'type' => $this->resolveType($state),
        ]);

        $state['draft_id'] = $draft->id;

        return $draft;
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{type: string, title: ?string, category_id: ?int, brand_id: ?int, description: ?string, location_id: ?int, location_detail: ?string, price: ?string}
     */
    private function listingAttributes(array $state): array
    {
        $fields = $state['fields'];

        // The title ends up in WhatsApp template parameters, which Meta
        // rejects over newlines and space runs — store it normalized.
        $title = WhatsappText::templateParameter((string) ($fields['title'] ?? ''));

        return [
            'type' => $this->resolveType($state)->value,
            'title' => $title === '' ? null : Str::limit($title, 255, ''),
            'category_id' => filled($fields['category'] ?? null)
                ? Category::query()->where('name', $fields['category'])->value('id')
                : null,
            'brand_id' => $this->resolveType($state) === ListingType::Equipment && filled($fields['brand'] ?? null)
                ? Brand::query()->where('name', $fields['brand'])->value('id')
                : null,
            'description' => $fields['description'] ?? null,
            'location_id' => $fields['location_id'] ?? null,
            'location_detail' => $fields['location_detail'] ?? null,
            'price' => $fields['price'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function resolveType(array $state): ListingType
    {
        return ListingType::tryFrom((string) ($state['fields']['type'] ?? ''))
            ?? ListingType::tryFrom((string) ($state['listing_type'] ?? ''))
            ?? ListingType::Equipment;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function sendConfirmation(BotSession $session, array $fields): void
    {
        $summary = filled($fields['summary'] ?? null) ? $fields['summary'] : $this->buildSummary($fields);

        // The summary repeats the supplier's own wording of the place, and
        // that wording is exactly what several dictionary places can share.
        // The bound node is shown with its parent chain, so a place the
        // collector settled without asking («Абайский район» — which of the
        // three?) is confirmed knowingly, not accepted blind.
        $place = filled($fields['location_id'] ?? null)
            ? Location::find($fields['location_id'])
            : null;

        // The composed title is the listing's future public name (search
        // rows, notifications, cabinets) — the supplier must see it before
        // submitting, not first meet it in a notification days later.
        $text = implode("\n", array_filter([
            filled($fields['title'] ?? null) ? 'Название: '.$fields['title'] : null,
            $summary,
            $place !== null ? 'Место: '.$place->label() : null,
        ]));

        $this->messenger->sendButtons($session->contact, $text."\nВсё верно? Нажмите «".self::BUTTON_SUBMIT_TITLE.'», чтобы отправить объявление на проверку.', [
            ['id' => self::BUTTON_SUBMIT, 'title' => self::BUTTON_SUBMIT_TITLE],
            ['id' => self::BUTTON_EDIT, 'title' => self::BUTTON_EDIT_TITLE],
        ]);
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function buildSummary(array $fields): string
    {
        $offer = collect([$fields['category'] ?? null, $fields['brand'] ?? null])->filter()->implode(' ');

        return collect([$offer, $fields['location'] ?? null, $fields['price'] ?? null])
            ->filter()
            ->implode(', ');
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  list<string>  $missing
     */
    private function clarificationQuestion(array $fields, array $missing): string
    {
        // The type question outranks whatever the extractor suggested: the
        // branch cannot finish while the listing type is unknown.
        if (($missing[0] ?? null) === 'type') {
            return 'Уточните: вы предлагаете технику в аренду или услугу (работу специалиста)?';
        }

        // The named place did not resolve to the dictionary — the extractor
        // believes the location is filled, so its question would miss this.
        // Two cases are not «не нашли» at all: the name IS in the dictionary,
        // shared by several places, and retyping it alone cannot help — only
        // a bigger unit can. Namesakes reached here either because there are
        // more of them than a list can hold, or because the pick list is
        // spent (MAX_LOCATION_LISTS) and the candidates are still known.
        if (($missing[0] ?? null) === 'location_id' && filled($fields['location'] ?? null)) {
            if (($fields['location_candidates'] ?? []) !== []) {
                return sprintf(
                    'Мест с названием «%s» в справочнике несколько, и мы не поняли, какое из них ваше. Напишите точнее — вместе с областью или районом.',
                    $fields['location'],
                );
            }

            return ($fields['location_overflow'] ?? false)
                ? sprintf(
                    'Мест с названием «%s» в справочнике слишком много. Напишите точнее — вместе с областью или районом.',
                    $fields['location'],
                )
                : sprintf(
                    'Не нашли «%s» в справочнике мест. Напишите город, район или село точнее.',
                    $fields['location'],
                );
        }

        if (filled($fields['clarifying_question'] ?? null)) {
            return (string) $fields['clarifying_question'];
        }

        $questions = [
            'category' => 'Что именно вы предлагаете — какая техника или услуга?',
            'description' => 'Опишите чуть подробнее ваше предложение.',
            'location_id' => 'В каком городе, районе или селе это доступно?',
            'price' => 'Какая цена или тариф?',
        ];

        return $questions[$missing[0]] ?? 'Уточните, пожалуйста, детали объявления.';
    }

    private function matchesButton(InboundMessage $message, string $id, string $title): bool
    {
        return $message->replyId === $id || mb_strtolower(trim((string) $message->text)) === mb_strtolower($title);
    }

    /**
     * The extracted location wording normalized for comparing turns: the
     * pick from the candidates list holds only while the extractor keeps
     * naming the place this same way.
     */
    private function locationWording(string $location): string
    {
        return mb_strtolower(trim($location));
    }

    /**
     * Restore state defaults so a mid-dialog code change or a missing row
     * cannot crash the collector.
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function normalizeState(BotSession $session, array $node): array
    {
        return array_merge([
            'phase' => 'collecting',
            'attempts' => 0,
            'unreadable' => 0,
            'location_lists' => 0,
            'provider_failures' => 0,
            'transcript' => [],
            'fields' => [],
            'draft_id' => null,
            'listing_type' => $node['listing_type'] ?? null,
            'picked_location_id' => null,
            'picked_location_wording' => null,
            'declined_location_wording' => null,
            'last_question' => null,
        ], $session->state ?? []);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function persist(BotSession $session, array $state): void
    {
        $session->state = $state;
        $session->save();
    }
}
