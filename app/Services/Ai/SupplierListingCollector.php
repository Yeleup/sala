<?php

namespace App\Services\Ai;

use App\Ai\Agents\ListingExtractionAgent;
use App\Ai\Agents\LocationChoiceAgent;
use App\Enums\AiOperationType;
use App\Enums\AiOutcome;
use App\Enums\BotReplyKey;
use App\Enums\LicenceType;
use App\Enums\ListingKind;
use App\Enums\ListingMediaType;
use App\Enums\ListingOrigin;
use App\Enums\ListingStatus;
use App\Enums\RepairPlace;
use App\Enums\UserIntent;
use App\Exceptions\OutboundRequestBlocked;
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
     * How many times the same enumerable-field button question may go out
     * before the field falls back to the ordinary clarification path. A
     * button press spends no clarification attempt, so a supplier who keeps
     * answering past the buttons would otherwise get them forever.
     */
    private const int MAX_BUTTON_PROMPTS = 2;

    /**
     * How many times the «Нет в списке» prompt may go out for a driver whose
     * machinery the category dictionary lacks. The prompt spends no
     * clarification attempt — it is an honest explanation plus a button, not
     * a question the supplier failed to answer — so it must be bounded on
     * its own: after the second one the machinery field closes by itself and
     * the questionnaire goes on to the summary with the machinery kept in
     * the driver's own words for the operator.
     */
    private const int MAX_UNLISTED_PROMPTS = 2;

    /**
     * Text clarifications in a row after which the set of missing fields has
     * not changed before the collector stops asking and hands over the web
     * form. Contact 225 (2026-09-04): «Автобус» was inexpressible in the
     * category enum, and the driver got the same «На какой технике вы
     * работаете» four times word for word, spending five of six attempts
     * on a question no answer could satisfy. A question that did not move
     * the questionnaire twice will not move it the third time.
     */
    private const int MAX_STALLED_TURNS = 2;

    /**
     * Questions about the service answered in a row before the collector
     * stops treating a message as one. The abuse case is not a person
     * asking four times but a stuck classification: the question is pulled
     * out of the transcript, so an unchanged rephrasing gets classified the
     * same way forever — for free, and bypassing MAX_LOCATION_LISTS, since
     * repeating the step re-sends the place list without counting it.
     */
    private const int MAX_SERVICE_QUESTIONS = 3;

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

    public const string LOCATION_ROW_PREFIX = 'listing_location:';

    public const string KIND_CHOICE_PREFIX = 'kind_choice:';

    public const string BUTTON_SUBMIT = 'listing_submit';

    public const string BUTTON_EDIT = 'listing_edit';

    /**
     * Button titles must fit the WhatsApp 20-character limit, or Meta
     * rejects the whole message asynchronously and the bot goes silent.
     */
    public const string BUTTON_SUBMIT_TITLE = 'Да, отправить';

    public const string BUTTON_EDIT_TITLE = 'Исправить';

    /** Releases the supplier from the questionnaire back to the main dialog. */
    public const string BUTTON_MENU = 'collect_to_menu';

    public const string BUTTON_MENU_TITLE = 'В меню';

    /**
     * Confirms leaving a non-empty questionnaire — offered next to
     * BUTTON_EXIT_STAY only while state['exit_confirm'] is set.
     */
    public const string BUTTON_EXIT_CONFIRM = 'collect_exit_yes';

    public const string BUTTON_EXIT_CONFIRM_TITLE = 'Да, в меню';

    /** Cancels the exit and repeats whatever step it interrupted. */
    public const string BUTTON_EXIT_STAY = 'collect_exit_no';

    public const string BUTTON_EXIT_STAY_TITLE = 'Продолжить анкету';

    /**
     * Closes the driver's machinery field when the dictionary has no
     * category for what they operate: the machinery stays in their own
     * words (unlisted_machinery) and the operator picks the category on
     * moderation. Offered only by the «Нет в списке» prompt, and — like
     * every button — recognized by id or by its typed title.
     */
    public const string BUTTON_MACHINERY_UNLISTED = 'collect_machinery_unlisted';

    public const string BUTTON_MACHINERY_UNLISTED_TITLE = 'Нет в списке';

    /**
     * WhatsApp lists hold at most 10 rows: at most this many places, the
     * last row reserved for the «В меню» exit. A candidate left out is
     * still pickable by typing its exact name — matchLocationChoice
     * matches against the full stored candidate list, not just what is
     * shown, mirroring the customer search.
     */
    private const int MAX_LOCATION_ROWS = 9;

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
        $kind = ListingKind::fromNode($node['kind'] ?? null);
        $known = $this->knownName($session, $kind);

        $session->state = [
            'kind' => $kind->value,
            'phase' => 'collecting',
            'attempts' => 0,
            'unreadable' => 0,
            'location_lists' => 0,
            'provider_failures' => 0,
            'service_questions' => 0,
            'transcript' => [],
            'fields' => $known === null ? [] : ['person_name' => $known],
            'draft_id' => null,
            'picked_location_id' => null,
            'picked_location_wording' => null,
            'declined_location_wording' => null,
            'last_question' => null,
            'button_prompts' => [],
            'button_answers' => [],
            'awaiting_document' => false,
            'exit_confirm' => false,
            'unlisted_prompts' => 0,
            'machinery_skipped' => false,
            'unlisted_machinery' => null,
            'stalled_missing' => null,
            'stalled_turns' => 0,
        ];
        // A fresh questionnaire outdates whatever an earlier one left behind
        // to resume — restoring it is not this method's job (see task 4).
        $session->paused_state = null;
        $session->save();

        $this->messenger->sendButtons(
            $session->contact,
            trim((string) ($node['text'] ?? '')) ?: $kind->greeting(),
            [['id' => self::BUTTON_MENU, 'title' => self::BUTTON_MENU_TITLE]],
        );

        return AiOutcome::InProgress;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    public function resume(BotSession $session, array $node, InboundMessage $message): AiOutcome
    {
        $state = $this->normalizeState($session);

        if (($movedOn = $this->completeIfDraftMovedOn($session, $state)) !== null) {
            return $movedOn;
        }

        // «В меню» — by button tap or its typed title, the scenario-wide
        // convention that typing a button's name equals pressing it
        // (matchesButton already covers BUTTON_SUBMIT/BUTTON_EDIT the same
        // way) — releases the supplier regardless of the phase. Real cases
        // 313/316/320/321/322 from the audit: the single button doubled as
        // «next», and contacts tapping it through a multi-step questionnaire
        // lost what they had already written. A non-empty questionnaire now
        // asks to confirm first; a second «В меню» while that question is
        // still open is the same intent asked twice, not a fresh request, so
        // it exits instead of looping the question forever.
        if ($this->matchesButton($message, self::BUTTON_MENU, self::BUTTON_MENU_TITLE)) {
            if ($state['exit_confirm'] === true) {
                return $this->exitToMenu($session, $state);
            }

            if (! $this->hasProgress($session, $state)) {
                return $this->exitToMenu($session, $state);
            }

            return $this->askExitConfirmation($session, $state);
        }

        if ($state['exit_confirm'] === true) {
            return $this->resolveExitConfirmation($session, $state, $message, $node);
        }

        return $this->dispatchPhase($session, $state, $message, $node);
    }

    /**
     * The block's ordinary per-phase routing, shared by an untouched turn
     * and one that just fell through the exit confirmation unanswered.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $node
     */
    private function dispatchPhase(BotSession $session, array $state, InboundMessage $message, array $node): AiOutcome
    {
        if ($state['phase'] === 'confirming') {
            return $this->handleConfirmation($session, $state, $message, $node);
        }

        if ($state['phase'] === 'locating') {
            return $this->handleLocating($session, $state, $message, $node);
        }

        if ($state['phase'] === 'choosing') {
            return $this->handleChoosing($session, $state, $message, $node);
        }

        return $this->handleCollecting($session, $state, $message, $node);
    }

    /**
     * The draft can leave the questionnaire's hands between turns: a
     * moderator publishes or deletes it straight from the admin panel, or
     * the supplier submits it from the web cabinet. Continuing the dialog
     * would edit a listing that is no longer a draft — or die trying to
     * re-submit it, which the contact used to see as «Не получилось
     * обработать сообщение» after the job ran out of retries. So any
     * message into such a session ends it with an honest status line
     * instead. Draft and Rejected stay in the questionnaire: both are
     * still the supplier's to edit. Null — the draft is where the
     * questionnaire left it, the turn proceeds as usual.
     *
     * @param  array<string, mixed>  $state
     */
    private function completeIfDraftMovedOn(BotSession $session, array $state): ?AiOutcome
    {
        if (! self::draftMovedOn($state, $session->contact_id)) {
            return null;
        }

        $draft = Listing::find($state['draft_id']);

        if ($draft === null) {
            $this->messenger->sendText(
                $session->contact,
                'Этот черновик уже удалён. Если нужно, начните заново из меню.',
            );

            return AiOutcome::Completed;
        }

        // A draft handed to another supplier by the operator: its signed web
        // links died with the ownership (assertLinkIssuedToCurrentOwner), and
        // the previous owner's chat session must not keep editing it either.
        // The status stays private — it is another supplier's listing now.
        if ($draft->contact_id !== $session->contact_id) {
            $this->messenger->sendText(
                $session->contact,
                'Этот черновик уже недоступен. Если нужно, начните заново из меню.',
            );

            return AiOutcome::Completed;
        }

        if ($draft->status === ListingStatus::PendingModeration) {
            $this->messenger->sendText(
                $session->contact,
                'Это объявление уже ушло на проверку. Как только модератор решит — сразу напишем.',
            );

            return AiOutcome::Completed;
        }

        $this->messenger->sendCtaUrl(
            $session->contact,
            $draft->status === ListingStatus::Archived
                ? 'Это объявление уже в архиве. Вернуть его в поиск можно из кабинета — кнопка ниже.'
                : 'Это объявление уже проверено и опубликовано. Управлять им можно в кабинете — кнопка ниже.',
            'Мои объявления',
            $this->cta->myListingsUrl($session->contact),
        );

        return AiOutcome::Completed;
    }

    /**
     * Whether the questionnaire state points at a draft that is no longer
     * the questionnaire's to edit — deleted, handed to another supplier by
     * the operator, or in any status besides Draft and Rejected. Public
     * with the navigator in mind: a paused snapshot whose draft moved on
     * must not be greeted with «всё написанное на месте» right before the
     * guard above ends the block.
     *
     * @param  array<string, mixed>  $state
     */
    public static function draftMovedOn(array $state, int $contactId): bool
    {
        if (($state['draft_id'] ?? null) === null) {
            return false;
        }

        $draft = Listing::find($state['draft_id']);

        return $draft === null
            || $draft->contact_id !== $contactId
            || ! in_array($draft->status, [ListingStatus::Draft, ListingStatus::Rejected], true);
    }

    /**
     * Ask whether the supplier really means to leave a non-empty
     * questionnaire, with one button per answer. The question itself spends
     * no clarification attempt and never touches the extractor — it is not
     * listing data.
     *
     * @param  array<string, mixed>  $state
     */
    private function askExitConfirmation(BotSession $session, array $state): AiOutcome
    {
        $state['exit_confirm'] = true;
        $this->persist($session, $state);
        $this->messenger->sendButtons(
            $session->contact,
            $this->replyTexts->get(BotReplyKey::CollectExitConfirm),
            [
                ['id' => self::BUTTON_EXIT_CONFIRM, 'title' => self::BUTTON_EXIT_CONFIRM_TITLE],
                ['id' => self::BUTTON_EXIT_STAY, 'title' => self::BUTTON_EXIT_STAY_TITLE],
            ],
        );

        return AiOutcome::InProgress;
    }

    /**
     * The answer to the exit confirmation: leave, stay and repeat whatever
     * the exit interrupted, or — anything else — the supplier ignored the
     * question and kept dictating, which is itself a «stay». The flag is
     * cleared in every case but the first: a resolved question must not
     * keep intercepting BUTTON_EXIT_CONFIRM/BUTTON_EXIT_STAY-shaped input on
     * a later, unrelated turn.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $node
     */
    private function resolveExitConfirmation(BotSession $session, array $state, InboundMessage $message, array $node): AiOutcome
    {
        if ($this->matchesButton($message, self::BUTTON_EXIT_CONFIRM, self::BUTTON_EXIT_CONFIRM_TITLE)) {
            return $this->exitToMenu($session, $state);
        }

        $state['exit_confirm'] = false;

        if ($this->matchesButton($message, self::BUTTON_EXIT_STAY, self::BUTTON_EXIT_STAY_TITLE)) {
            $this->persist($session, $state);
            $this->repeatCurrentStep($session, $state, $node);

            return AiOutcome::InProgress;
        }

        return $this->dispatchPhase($session, $state, $message, $node);
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $node
     */
    private function handleCollecting(BotSession $session, array $state, InboundMessage $message, array $node): AiOutcome
    {
        // «Нет в списке» — by button or its typed title — closes the driver's
        // machinery field: what they operate stays in their own words and
        // the operator picks the category on moderation. Resolved before
        // intake: the press is an answer to the bot, not listing data, so it
        // never reaches the transcript or the model. Only meaningful while
        // machinery in words is actually remembered — without it the same
        // words are ordinary text and walk the usual path.
        if ($this->matchesButton($message, self::BUTTON_MACHINERY_UNLISTED, self::BUTTON_MACHINERY_UNLISTED_TITLE)
            && filled($state['unlisted_machinery'] ?? ($state['fields']['unlisted_machinery'] ?? null))) {
            $state['machinery_skipped'] = true;
            $state['phase'] = 'collecting';

            return $this->advance($session, $state);
        }

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
            $this->messenger->sendButtons(
                $session->contact,
                'Сообщение не разобралось. Опишите предложение текстом, голосом или фото с подписью.',
                [['id' => self::BUTTON_MENU, 'title' => self::BUTTON_MENU_TITLE]],
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
            $this->messenger->sendButtons(
                $session->contact,
                'Что-то сбоит на нашей стороне. Отправьте сообщение, пожалуйста, ещё раз.',
                [['id' => self::BUTTON_MENU, 'title' => self::BUTTON_MENU_TITLE]],
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
        if ($intent === UserIntent::Abandoned) {
            $state['transcript'] = array_slice($state['transcript'], 0, $intakeMark);

            return $this->abandon($session, $state);
        }

        // A worded request for the menu is the same exit as the «В меню»
        // button, just spelled out instead of tapped — the transcript is
        // rolled back exactly like a refusal, and the draft is preserved
        // the same way (exitToMenu mirrors abandon()).
        if ($intent === UserIntent::MenuRequested) {
            $state['transcript'] = array_slice($state['transcript'], 0, $intakeMark);

            return $this->exitToMenu($session, $state);
        }

        // The free answer is bounded like every other exit of the block.
        // Past the limit the message walks the ordinary task path: it stays
        // in the transcript, feeds the extraction and spends a clarification
        // attempt like any other, so the clarification limit — and behind it
        // the web form — carries the dialog to its end.
        if ($intent === UserIntent::ServiceQuestion && $state['service_questions'] < self::MAX_SERVICE_QUESTIONS) {
            $state['transcript'] = array_slice($state['transcript'], 0, $intakeMark);
            $state['service_questions']++;

            return $this->answerServiceQuestion($session, $state, $node);
        }

        // Any message that is not a question about the service ends the
        // streak; the one that spent the limit is still such a question and
        // keeps it, so further ones keep walking the task path.
        if ($intent !== UserIntent::ServiceQuestion) {
            $state['service_questions'] = 0;
        }

        // The extraction rebuilds the fields from scratch each turn, which
        // would erase a value picked with a button. Reapply the picks
        // underneath: a value the model got out of the words wins — the
        // supplier may have changed their mind in words.
        foreach (($state['button_answers'] ?? []) as $field => $value) {
            $fields[$field] = $fields[$field] ?? $value;
        }

        // Machinery named in the driver's own words survives the rebuild the
        // same way: remembered once extracted, put back underneath a turn
        // that said nothing about the machinery at all (the turn about the
        // experience). The words of the current turn win, and a turn that
        // names dictionary machinery while dropping the word is not a slip
        // but a retraction («не автобус — экскаватор»): the model reads the
        // whole transcript, so the word is let go rather than put back.
        if (filled($fields['unlisted_machinery'] ?? null)) {
            $state['unlisted_machinery'] = $fields['unlisted_machinery'];
        } elseif (filled($fields['machine_categories'] ?? null)) {
            $state['unlisted_machinery'] = null;
        } elseif (filled($state['unlisted_machinery'] ?? null)) {
            $fields['unlisted_machinery'] = $state['unlisted_machinery'];
        }

        // The supplier introduces themselves once, not once per listing.
        $this->rememberName($session, $fields['person_name'] ?? null);

        // The name already known goes underneath the extraction, exactly
        // like a button answer: a name said in words still wins, because
        // the supplier may be introducing themselves differently here.
        if (blank($fields['person_name'] ?? null)) {
            $known = $this->knownName($session, $this->kind($state));

            if ($known !== null) {
                $fields['person_name'] = $known;
            }
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
        $kind = $this->kind($state);
        $missing = $this->missingFieldsOf($state);

        if ($missing === []) {
            $draft = $this->ensureDraft($session, $state);
            $draft->update($this->listingAttributes($state));
            $this->syncMachineCategories($draft, $state);

            // A driver's licence document is mandatory but is not a field:
            // the summary goes out without the submit button and asks for
            // the photo instead, for free — like a button prompt, it is not
            // a clarification attempt.
            if ($kind->requiresDocument() && $draft->documents()->doesntExist()) {
                $state['phase'] = 'confirming';
                $state['awaiting_document'] = true;
                $this->persist($session, $state);
                $this->sendConfirmation($session, $state);

                return AiOutcome::InProgress;
            }

            $state['awaiting_document'] = false;
            $state['phase'] = 'confirming';
            $this->persist($session, $state);
            $this->sendConfirmation($session, $state);

            return AiOutcome::InProgress;
        }

        // The supplier's own name outranks the rest of the questionnaire:
        // while it is unknown the collector asks for nothing else. It is
        // what the listing is signed with and what every later message
        // addresses them by, so a dialog abandoned halfway still leaves
        // someone to write to. The steps that yield to it — the place list,
        // the button fields — cost no clarification attempt, so waiting a
        // turn costs them nothing either.
        $asksName = in_array('person_name', $missing, true);

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
            if (! $asksName && $state['location_lists'] < self::MAX_LOCATION_LISTS) {
                $state['location_lists']++;
                $state['phase'] = 'locating';
                $this->persist($session, $state);
                $this->sendLocationChoices($session, $candidates);

                return AiOutcome::InProgress;
            }
        }

        // An enumerated field with few fixed options is asked with buttons,
        // not with a text question: the press costs no clarification attempt
        // and cannot misspell. Bounded per field — past the limit the field
        // walks the ordinary clarification path below.
        foreach ($asksName ? [] : $kind->buttonFields() as $field => $prompt) {
            if (! in_array($field, $missing, true)) {
                continue;
            }

            if (($state['button_prompts'][$field] ?? 0) >= self::MAX_BUTTON_PROMPTS) {
                continue;
            }

            $state['button_prompts'][$field] = ($state['button_prompts'][$field] ?? 0) + 1;
            $state['phase'] = 'choosing';
            $state['button_field'] = $field;
            $this->persist($session, $state);
            $this->sendButtonPrompt($session, $field, $prompt);

            return AiOutcome::InProgress;
        }

        // The driver named machinery the dictionary has no category for: a
        // text question about the machinery cannot be answered — the schema
        // enum has no room for «автобус» — so the bot says so honestly and
        // offers to close the field. Free like a button prompt and bounded
        // like one; past the limit the field closes by itself and the
        // questionnaire goes on, the machinery kept in words for the
        // operator. The bounded re-entry recomputes what is still missing.
        if (! $asksName
            && in_array('machine_categories', $missing, true)
            && filled($state['fields']['unlisted_machinery'] ?? null)) {
            if ($state['unlisted_prompts'] < self::MAX_UNLISTED_PROMPTS) {
                $state['unlisted_prompts']++;
                $state['phase'] = 'collecting';
                $state['last_question'] = $this->unlistedMachineryPrompt($state['fields']['unlisted_machinery']);
                $this->persist($session, $state);
                $this->messenger->sendButtons($session->contact, $state['last_question'], [
                    ['id' => self::BUTTON_MACHINERY_UNLISTED, 'title' => self::BUTTON_MACHINERY_UNLISTED_TITLE],
                    ['id' => self::BUTTON_MENU, 'title' => self::BUTTON_MENU_TITLE],
                ]);

                return AiOutcome::InProgress;
            }

            $state['machinery_skipped'] = true;

            return $this->advance($session, $state);
        }

        if ($state['attempts'] >= $kind->maxClarifications()) {
            return $this->handOffToWebForm($session, $state);
        }

        // A text question that left the set of missing fields exactly as it
        // was is not moving the questionnaire; the same question a third
        // time in a row would only burn the limit (MAX_STALLED_TURNS). Only
        // text clarifications count: button prompts, place lists and the
        // «Нет в списке» prompt return above without touching the streak,
        // and a turn that filled anything changes the set and resets it.
        if (($state['stalled_missing'] ?? null) === $missing) {
            $state['stalled_turns']++;
        } else {
            $state['stalled_missing'] = $missing;
            $state['stalled_turns'] = 0;
        }

        if ($state['stalled_turns'] >= self::MAX_STALLED_TURNS) {
            return $this->handOffToWebForm($session, $state);
        }

        $state['attempts']++;
        $state['phase'] = 'collecting';
        $state['last_question'] = $this->clarificationQuestion($state['fields'], $missing, $kind);
        $this->persist($session, $state);
        $this->messenger->sendButtons(
            $session->contact,
            $state['last_question'],
            [['id' => self::BUTTON_MENU, 'title' => self::BUTTON_MENU_TITLE]],
        );

        return AiOutcome::InProgress;
    }

    /**
     * The honest prompt for machinery the dictionary lacks (copy text №1 of
     * the plan): names the driver's own word so they see it was understood,
     * offers the listed alternatives, and explains what the button does.
     */
    private function unlistedMachineryPrompt(string $machinery): string
    {
        return sprintf(
            '«%s» в нашем списке техники пока нет. Если работаете ещё на чём-то из списка — например, экскаватор, самосвал, кран — напишите. Если нет — нажмите «%s», и категорию подберёт оператор.',
            $this->unlistedMachineryWord($machinery),
            self::BUTTON_MACHINERY_UNLISTED_TITLE,
        );
    }

    /**
     * The driver's word for their machinery as the bot quotes it back:
     * trimmed, first letter capitalized — «Автобус», not «автобус».
     */
    private function unlistedMachineryWord(string $machinery): string
    {
        return Str::ucfirst(trim($machinery));
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
        $this->syncMachineCategories($draft, $state);
        $this->persist($session, $state);

        // Reached from the confirmation phase the data IS collected: the bot
        // was waiting for a button press, not for a missing field, so the
        // «не получилось собрать» wording would be untrue.
        $collected = $state['phase'] === 'confirming';

        $this->messenger->sendCtaUrl(
            $session->contact,
            $collected
                ? 'Всё собрано. Осталось проверить и отправить — это в форме по кнопке ниже.'
                : 'Часть данных из переписки собрать не вышло. Удобнее закончить в форме по кнопке ниже — всё собранное уже там.',
            $collected ? 'Открыть объявление' : 'Заполнить вручную',
            $this->cta->editUrl($draft),
        );

        return AiOutcome::Completed;
    }

    /**
     * Whether the questionnaire holds anything worth keeping: an existing
     * draft — it may already hold photos or audio even when no field got
     * extracted — or a field the extraction already filled in. Two values
     * are excluded because the supplier did not give them: the kind, which
     * comes from the scenario node, and a name equal to the one start()
     * pre-filled from the contact. Shared by every draft-preserving exit
     * (abandon(), exitToMenu()) so they agree on what «nothing collected»
     * means.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $attributes
     */
    private function hasContent(BotSession $session, array $state, array $attributes): bool
    {
        if ($state['draft_id'] !== null) {
            return true;
        }

        $known = $this->knownName($session, $this->kind($state));

        return collect($attributes)
            ->except('kind')
            ->contains(fn (mixed $value, string $field): bool => filled($value)
                && ! ($field === 'person_name' && $value === $known));
    }

    /**
     * Whether the questionnaire has anything an interruption would lose: the
     * draft/field content hasContent() checks, or a transcript entry not yet
     * run through the extractor — undictated is still progress, since the
     * next turn's extraction call would turn it into fields. Gates the exit
     * confirmation (askExitConfirmation()): an empty questionnaire leaves
     * straight through exitToMenu() as before, case 322 from the audit.
     *
     * @param  array<string, mixed>  $state
     */
    private function hasProgress(BotSession $session, array $state): bool
    {
        return $this->hasContent($session, $state, $this->listingAttributes($state)) || $state['transcript'] !== [];
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

        if (! $this->hasContent($session, $state, $attributes)) {
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
     * «В меню» — by button, its typed title, or the worded intent — is the
     * same draft-preserving exit as an explicit refusal (abandon()), with
     * one difference: honesty about where the contact is headed. A saved
     * draft still gets one line, because the supplier is leaving with
     * something; an empty draft stays silent, because the main menu
     * answers for itself right after — a second «ничего не сохранили»
     * would just repeat what the empty exit already says by saying
     * nothing.
     *
     * Whenever there is any progress to lose (hasProgress() — a broader
     * check than the draft/field content below, since a bare transcript
     * still counts), a snapshot of exactly where the dialog stood goes into
     * paused_state — captured only once any draft below is actually created
     * and updated, so paused_state.state.draft_id names the very draft
     * persist() is about to save into session.state. Snapshotting earlier,
     * before ensureDraft() runs, would leave draft_id null in the snapshot
     * even though a draft was created moments later in the very same call —
     * a future resume would then create a second, duplicate draft instead
     * of continuing the first. exit_confirm is forced false inside the
     * snapshot so the very next answer after a resume is not mistaken for
     * the confirmation branch.
     *
     * @param  array<string, mixed>  $state
     */
    private function exitToMenu(BotSession $session, array $state): AiOutcome
    {
        $attributes = $this->listingAttributes($state);
        $shouldSnapshot = $this->hasProgress($session, $state);

        if (! $this->hasContent($session, $state, $attributes)) {
            if ($shouldSnapshot) {
                $this->snapshotPausedState($session, $state);
            }

            $this->persist($session, $state);

            return AiOutcome::Completed;
        }

        $draft = $this->ensureDraft($session, $state);
        $draft->update($attributes);

        if ($shouldSnapshot) {
            $this->snapshotPausedState($session, $state);
        }

        $this->persist($session, $state);
        $this->messenger->sendText($session->contact, 'Черновик сохранили — он ждёт в кабинете.');

        return AiOutcome::Completed;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function snapshotPausedState(BotSession $session, array $state): void
    {
        $session->paused_state = [
            'node_id' => $session->current_node_id,
            'fingerprint' => $session->current_node_fingerprint,
            'state' => [...$state, 'exit_confirm' => false],
            'saved_at' => now()->toIso8601String(),
        ];
    }

    /**
     * A question about the service is answered with the operator's own
     * built-in reply and costs nothing: no clarification attempt, and the
     * message stays out of the listing data. The bot then repeats whatever
     * it was waiting for, so the dialog does not stall on an open question.
     * Bounded by MAX_SERVICE_QUESTIONS in a row.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $node
     */
    private function answerServiceQuestion(BotSession $session, array $state, array $node): AiOutcome
    {
        $this->persist($session, $state);
        $this->messenger->sendText($session->contact, $this->replyTexts->get(BotReplyKey::ServiceQuestion));
        $this->repeatCurrentStep($session, $state, $node);

        return AiOutcome::InProgress;
    }

    /**
     * Re-send whatever the collector is waiting for at this phase. Before
     * the first clarifying question that is the block's greeting — the one
     * the operator writes in the scenario editor, not the built-in text.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $node
     */
    private function repeatCurrentStep(BotSession $session, array $state, array $node): void
    {
        if ($state['phase'] === 'confirming') {
            $this->sendConfirmation($session, $state);

            return;
        }

        if ($state['phase'] === 'locating') {
            $this->sendLocationChoices(
                $session,
                array_map(intval(...), (array) ($state['fields']['location_candidates'] ?? [])),
            );

            return;
        }

        if ($state['phase'] === 'choosing') {
            $field = (string) ($state['button_field'] ?? '');
            $prompt = $this->kind($state)->buttonFields()[$field] ?? null;

            // The re-send does not count against MAX_BUTTON_PROMPTS: a
            // question about the service must not burn the button step.
            if ($prompt !== null) {
                $this->sendButtonPrompt($session, $field, $prompt);

                return;
            }
        }

        $question = trim((string) ($state['last_question'] ?? ''));
        $greeting = trim((string) ($node['text'] ?? ''))
            ?: $this->kind($state)->greeting();

        // The open question may be the «Нет в списке» prompt: its text tells
        // the driver to press a button, so the re-send carries that button
        // too — a prompt naming a button the screen does not show would
        // read as a broken promise.
        $machinery = (string) ($state['fields']['unlisted_machinery'] ?? $state['unlisted_machinery'] ?? '');
        $buttons = $machinery !== '' && $question === $this->unlistedMachineryPrompt($machinery)
            ? [
                ['id' => self::BUTTON_MACHINERY_UNLISTED, 'title' => self::BUTTON_MACHINERY_UNLISTED_TITLE],
                ['id' => self::BUTTON_MENU, 'title' => self::BUTTON_MENU_TITLE],
            ]
            : [['id' => self::BUTTON_MENU, 'title' => self::BUTTON_MENU_TITLE]];

        $this->messenger->sendButtons(
            $session->contact,
            $question !== '' ? $question : $greeting,
            $buttons,
        );
    }

    /**
     * The supplier picks one of the matching dictionary locations — by the
     * list row or by typing a row's title (the scenario-wide convention).
     * Any other reply is treated as further details.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $node
     */
    private function handleLocating(BotSession $session, array $state, InboundMessage $message, array $node): AiOutcome
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

        return $this->handleCollecting($session, $state, $message, $node);
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
            ->limit(self::MAX_LOCATION_ROWS)
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
            [...$rows, ['id' => self::BUTTON_MENU, 'title' => self::BUTTON_MENU_TITLE]],
        );
    }

    /**
     * The supplier answers an enumerable-field button question — by the
     * button or by typing its title (the scenario-wide convention). The
     * answer lands in the fields directly, with no model call and no spent
     * attempt; any other reply is treated as further details.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $node
     */
    private function handleChoosing(BotSession $session, array $state, InboundMessage $message, array $node): AiOutcome
    {
        $field = (string) ($state['button_field'] ?? '');
        $options = $this->kind($state)->buttonFields()[$field]['options'] ?? [];
        $value = $this->matchButtonChoice($options, $field, $message);

        if ($value !== null) {
            // yes/no options are the questionnaire's boolean fields: store
            // the same bool the extraction would produce from words.
            $answer = match ($value) {
                'yes' => true,
                'no' => false,
                default => $value,
            };

            // The extraction rebuilds the fields from scratch each turn, so
            // the answer is also kept aside to reapply — like a location pick.
            $state['fields'][$field] = $answer;
            $state['button_answers'][$field] = $answer;
            $state['phase'] = 'collecting';
            unset($state['button_field']);

            return $this->advance($session, $state);
        }

        return $this->handleCollecting($session, $state, $message, $node);
    }

    /**
     * @param  array<string, string>  $options
     */
    private function matchButtonChoice(array $options, string $field, InboundMessage $message): ?string
    {
        if ($options === []) {
            return null;
        }

        $replyId = (string) $message->replyId;

        if (str_starts_with($replyId, self::KIND_CHOICE_PREFIX)) {
            $parts = explode(':', Str::after($replyId, self::KIND_CHOICE_PREFIX), 2);

            return count($parts) === 2 && $parts[0] === $field && array_key_exists($parts[1], $options)
                ? $parts[1]
                : null;
        }

        $text = mb_strtolower(trim((string) $message->text));

        if ($text === '') {
            return null;
        }

        foreach ($options as $value => $title) {
            if (mb_strtolower($title) === $text) {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * @param  array{question: string, options: array<string, string>}  $prompt
     */
    private function sendButtonPrompt(BotSession $session, string $field, array $prompt): void
    {
        $this->messenger->sendButtons(
            $session->contact,
            $prompt['question'],
            collect($prompt['options'])
                ->map(fn (string $title, string $value): array => [
                    'id' => self::KIND_CHOICE_PREFIX.$field.':'.$value,
                    'title' => $title,
                ])
                ->values()
                ->all(),
        );
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $node
     */
    private function handleConfirmation(BotSession $session, array $state, InboundMessage $message, array $node): AiOutcome
    {
        $draft = $state['draft_id'] !== null ? Listing::find($state['draft_id']) : null;

        if ($this->matchesButton($message, self::BUTTON_SUBMIT, self::BUTTON_SUBMIT_TITLE)) {
            // The submit button was never offered while the licence document
            // is missing, but its title can be typed by hand: repeat the
            // summary-plus-ask instead of letting an undocumented driver
            // listing into moderation.
            if ($this->kind($state)->requiresDocument() && ! ($draft?->documents()->exists() ?? false)) {
                $state['awaiting_document'] = true;
                $this->persist($session, $state);
                $this->sendConfirmation($session, $state);

                return AiOutcome::InProgress;
            }

            $draft?->submitForModeration();
            $this->messenger->sendText($session->contact, 'Готово! Объявление ушло на проверку. Как только модератор решит — сразу напишем.');

            return AiOutcome::Completed;
        }

        if ($this->matchesButton($message, self::BUTTON_EDIT, self::BUTTON_EDIT_TITLE)) {
            if ($draft !== null) {
                $this->messenger->sendCtaUrl(
                    $session->contact,
                    'Чтобы изменить объявление, нажмите на кнопку ниже. Диалог в чате на этом закончим, черновик сохранён.',
                    'Открыть объявление',
                    $this->cta->editUrl($draft),
                );

                return AiOutcome::Completed;
            }

            // The draft may have been deleted between the summary and the
            // tap (e.g. submitted and then taken down from the cabinet):
            // silence here would be an unexplained dead end.
            $this->messenger->sendText(
                $session->contact,
                'Этот черновик уже удалён — править нечего. Если нужно, начните заново из меню.',
            );

            return AiOutcome::Completed;
        }

        // Anything else during confirmation is treated as more details:
        // re-collect, re-extract and confirm again.
        return $this->handleCollecting($session, $state, $message, $node);
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

        // A failed download must not kill the dialog (Dereu 403/5xx are a
        // known failure profile): the photo is simply not attached, the
        // caption still counts as ordinary text, and a photo-only message
        // walks the unreadable path — mirroring how voice failures behave.
        try {
            $download = $this->mediaDownloader->download((string) $message->mediaId);
        } catch (OutboundRequestBlocked $e) {
            // See the voice path: a local block is not a failure profile of
            // the photo, and must not be dressed as one.
            throw $e;
        } catch (Throwable $e) {
            Log::warning('Photo download failed; the message is treated as unreadable.', [
                'bot_session_id' => $session->id,
                'media_id' => $message->mediaId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        $draft = $this->ensureDraft($session, $state);

        // The bot just asked for the licence document, so the next photo IS
        // the document: stored on the non-public disk, never rendered to
        // customers and never attached to extraction calls. Only while the
        // summary-plus-ask is the open question: a wording correction can
        // drop a required field and detour the dialog back to clarifying
        // with awaiting_document still set — a photo answering THAT question
        // is ordinary listing material, and the document gets asked for
        // again on the next full summary.
        if ($state['phase'] === 'confirming'
            && ($state['awaiting_document'] ?? false)
            && $draft->documents()->doesntExist()) {
            $path = "listings/{$draft->id}/documents/".uniqid('', true).'.jpg';
            Storage::disk('local')->put($path, $download['contents']);

            ListingMedia::create([
                'listing_id' => $draft->id,
                'type' => ListingMediaType::Document,
                'disk' => 'local',
                'path' => $path,
            ]);

            $state['awaiting_document'] = false;

            return true;
        }

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
        $kind = $this->kind($state);

        // Each kind carries only its own dictionaries: the rental picks a
        // category and a brand, the driver picks machine categories, the
        // repair questionnaire has neither.
        $categories = $kind === ListingKind::Repair
            ? new Collection
            : Category::query()->orderBy('name')->get();

        $brands = $kind === ListingKind::Rental
            ? Brand::query()->orderBy('name')->get()
            : new Collection;

        $prompt = $state['transcript'] !== []
            ? implode("\n", $state['transcript'])
            : 'Поставщик прислал только фотографии — извлеки из них, что сможешь.';

        // A short reply («не надо», «да») only reads against the question
        // it answers: without the bot's side the model takes a refusal of
        // the photo ask for abandoning the listing.
        $botMessage = $this->currentBotMessageSummary($state);

        if ($botMessage !== null) {
            $prompt = "Последнее сообщение бота поставщику: {$botMessage}\n\nСообщения поставщика:\n{$prompt}";
        }

        $agent = match ($kind) {
            ListingKind::Rental => new ListingExtractionAgent($kind, $categories->pluck('name')->all(), $brands->pluck('name')->all()),
            ListingKind::Repair => new ListingExtractionAgent($kind),
            ListingKind::Driver => new ListingExtractionAgent($kind, $categories->pluck('name')->all()),
        };

        try {
            $fields = $this->audit->run(
                AiOperationType::ListingExtraction,
                fn (): array => $agent
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

        if ($kind === ListingKind::Rental) {
            $fields['category'] = $this->canonicalCategory($fields['category'] ?? null, $categories)?->name;
            $fields['brand'] = $this->canonicalBrand($fields['brand'] ?? null, $brands)?->name;
        }

        if ($kind === ListingKind::Driver) {
            // Null stays null: it means «not extracted yet», and later it
            // must not erase machine categories synced on an earlier turn.
            $fields['machine_categories'] = is_array($fields['machine_categories'] ?? null)
                ? $this->canonicalMachineCategories($fields['machine_categories'], $categories)
                : null;

            $fields['unlisted_machinery'] = $this->normalizeUnlistedMachinery($fields['unlisted_machinery'] ?? null);

            // A word that does match a dictionary category (the operator
            // added it, the model did not notice) is machinery from the
            // list, not outside it: moved over, so the «Нет в списке» prompt
            // never goes out for machinery the dictionary does have.
            $listed = $this->canonicalCategory($fields['unlisted_machinery'], $categories);

            if ($listed !== null) {
                $fields['machine_categories'] = collect($fields['machine_categories'] ?? [])
                    ->push($listed->name)
                    ->unique()
                    ->values()
                    ->all();
                $fields['unlisted_machinery'] = null;
            }
        }

        return $this->resolveLocation($fields, $state);
    }

    /**
     * What the bot last sent, compressed for the extractor's context line.
     * Null when the dialog has no open question yet (the greeting).
     *
     * @param  array<string, mixed>  $state
     */
    private function currentBotMessageSummary(array $state): ?string
    {
        if ($state['phase'] === 'confirming') {
            if ($state['awaiting_document'] ?? false) {
                return 'показал сводку и попросил прислать фото удостоверения';
            }

            return 'показал сводку объявления с кнопками «Да, отправить» и «Исправить», спросил «Всё верно?»'
                .($this->hasPhotos($state) ? '' : ' и попросил прислать фотографии');
        }

        if ($state['phase'] === 'locating') {
            return 'прислал список одноимённых мест и попросил выбрать нужное';
        }

        if ($state['phase'] === 'choosing') {
            $question = $this->kind($state)->buttonFields()[$state['button_field'] ?? '']['question'] ?? '';

            if ($question !== '') {
                return 'задал вопрос с кнопками: «'.$question.'»';
            }
        }

        $question = trim((string) ($state['last_question'] ?? ''));

        return $question !== '' ? 'задал вопрос: «'.$question.'»' : null;
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
     * The driver's machine categories, kept only where they match the
     * category dictionary (the schema enum already enforces this — the
     * lookup is a safety net), normalized to the dictionary spelling.
     *
     * @param  Collection<int, Category>  $categories
     * @return list<string>
     */
    private function canonicalMachineCategories(mixed $names, Collection $categories): array
    {
        return collect(is_array($names) ? $names : [])
            ->map(fn (mixed $name): ?Category => $this->canonicalCategory($name, $categories))
            ->filter()
            ->map(fn (Category $category): string => $category->name)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The machinery the driver named in their own words because the
     * dictionary had no category for it, as the listing stores and the bot
     * quotes it — trimmed, first letter capitalized like a category name —
     * or null when nothing was named.
     */
    private function normalizeUnlistedMachinery(mixed $named): ?string
    {
        if (! is_string($named) || trim($named) === '') {
            return null;
        }

        return $this->unlistedMachineryWord($named);
    }

    /**
     * Bind the extracted machine categories to the driver's draft. Only
     * when the extraction returned an array: the fields are rebuilt from
     * scratch each turn, and a turn where the model returned null must not
     * erase categories synced earlier.
     *
     * @param  array<string, mixed>  $state
     */
    private function syncMachineCategories(Listing $draft, array $state): void
    {
        if ($this->kind($state) !== ListingKind::Driver || ! is_array($state['fields']['machine_categories'] ?? null)) {
            return;
        }

        $draft->machineCategories()->sync(
            Category::query()->whereIn('name', $state['fields']['machine_categories'])->pluck('id'),
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
     * Whether the draft already carries a photo. No draft yet means no
     * photos — the confirmation then asks for them, which is the answer the
     * caller wants and not merely a guard against a missing id.
     *
     * @param  array<string, mixed>  $state
     */
    private function hasPhotos(array $state): bool
    {
        if ($state['draft_id'] === null) {
            return false;
        }

        return Listing::find($state['draft_id'])?->photos()->exists() === true;
    }

    /**
     * The supplier's own name as the system already knows it: set by them
     * in the web cabinet, by the operator, or remembered from an earlier
     * questionnaire. Only a deliberately set name counts — the WhatsApp
     * profile name is a handle the contact picked for themselves and may
     * be an emoji or a slogan, which is not what a performer's card should
     * be signed with. Rental listings carry no such field at all.
     */
    private function knownName(BotSession $session, ListingKind $kind): ?string
    {
        if (! in_array('person_name', $kind->collectorRequiredFields(), true)) {
            return null;
        }

        return blank($session->contact->display_name) ? null : $session->contact->display_name;
    }

    /**
     * Remember the name the supplier gave the questionnaire as the
     * contact's own: their next listing then costs no clarification asking
     * again, and the bot can address them by it outside this block. A name
     * set by hand — in the cabinet or by the operator — is never
     * overwritten, and neither is a name already remembered this way.
     */
    private function rememberName(BotSession $session, mixed $name): void
    {
        if (! is_string($name) || trim($name) === '' || filled($session->contact->display_name)) {
            return;
        }

        $session->contact->update(['display_name' => Str::limit(trim($name), 255, '')]);
    }

    /**
     * What the questionnaire still lacks, minus the field the dialog has
     * closed: the driver's machine_categories once «Нет в списке» was
     * pressed or the prompt for it ran out. The exception lives here so
     * missingFields() stays a pure function of the fields and the kind.
     *
     * @param  array<string, mixed>  $state
     * @return list<string>
     */
    private function missingFieldsOf(array $state): array
    {
        $missing = $this->missingFields($state['fields'], $this->kind($state));

        if (($state['machinery_skipped'] ?? false) !== true) {
            return $missing;
        }

        return array_values(array_diff($missing, ['machine_categories']));
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return list<string>
     */
    private function missingFields(array $fields, ListingKind $kind): array
    {
        return array_values(array_filter(
            $kind->collectorRequiredFields(),
            function (string $field) use ($fields): bool {
                $value = $fields[$field] ?? null;

                // false is an answer («не готов выезжать»), [] is not.
                return is_bool($value) ? false : blank($value);
            },
        ));
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
            'kind' => $this->kind($state),
        ]);

        $state['draft_id'] = $draft->id;

        return $draft;
    }

    /**
     * The draft attributes assembled from the kind: the common core plus
     * the kind's own questionnaire fields.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function listingAttributes(array $state): array
    {
        $fields = $state['fields'];
        $kind = $this->kind($state);

        // The title ends up in WhatsApp template parameters, which Meta
        // rejects over newlines and space runs — store it normalized.
        $title = WhatsappText::templateParameter((string) ($fields['title'] ?? ''));

        $common = [
            'kind' => $kind,
            'title' => $title === '' ? null : Str::limit($title, 255, ''),
            'description' => $fields['description'] ?? null,
            'location_id' => $fields['location_id'] ?? null,
            'location_detail' => $fields['location_detail'] ?? null,
        ];

        return $common + match ($kind) {
            ListingKind::Rental => [
                'category_id' => filled($fields['category'] ?? null)
                    ? Category::query()->where('name', $fields['category'])->value('id') : null,
                'brand_id' => filled($fields['brand'] ?? null)
                    ? Brand::query()->where('name', $fields['brand'])->value('id') : null,
                'price' => $fields['price'] ?? null,
            ],
            ListingKind::Repair => [
                'person_name' => $fields['person_name'] ?? null,
                'services' => $fields['services'] ?? null,
                'repair_place' => $fields['repair_place'] ?? null,
                'price' => $fields['price'] ?? null,
            ],
            ListingKind::Driver => [
                'person_name' => $fields['person_name'] ?? null,
                'licence_type' => $fields['licence_type'] ?? null,
                'experience_years' => $fields['experience_years'] ?? null,
                'travels_to_other_cities' => $fields['travels_to_other_cities'] ?? null,
                'unlisted_machinery' => $fields['unlisted_machinery'] ?? null,
            ],
        };
    }

    /**
     * The confirmation is also the one moment the bot knows the collection
     * succeeded and no photo ever arrived, so a listing heading for
     * moderation as an empty card in the catalog is asked for pictures here
     * — not earlier, where a separate «пришлите фото» step would cost a
     * round trip on every dialog, photos or not. The ask never blocks:
     * photos are not a required field, «Да, отправить» stays right under it,
     * and a photo sent instead of pressing it is ordinary further detail
     * (handleConfirmation) that attaches to the draft and re-confirms. The
     * line is a property of the summary rather than a one-off event — it
     * stands while the draft has no photos and disappears once it has one.
     *
     * @param  array<string, mixed>  $state
     */
    private function sendConfirmation(BotSession $session, array $state): void
    {
        $fields = $state['fields'];
        $summary = filled($fields['summary'] ?? null)
            ? $fields['summary']
            : $this->buildSummary($fields, $this->kind($state));

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
        // Machinery only in the driver's words, with no dictionary category
        // beside it — neither on this turn nor synced to the draft earlier
        // (a turn where the model returned null does not erase those): the
        // listing goes to moderation flagged for the operator, and the
        // driver is told so before submitting — not left to wonder why the
        // summary names no category.
        $unlisted = filled($fields['unlisted_machinery'] ?? null)
            && blank($fields['machine_categories'] ?? null)
            && ! $this->draftHasMachineCategories($state)
            ? sprintf(
                'Техника «%s» — не из нашего списка: категорию подберёт оператор при проверке.',
                $this->unlistedMachineryWord((string) $fields['unlisted_machinery']),
            )
            : null;

        $text = implode("\n", array_filter([
            filled($fields['title'] ?? null) ? 'Название: '.$fields['title'] : null,
            $summary,
            $unlisted,
            $place !== null ? 'Место: '.$place->label() : null,
        ]));

        // The licence document is still missing: the summary goes out
        // without the submit button — submitting is simply not offered
        // until the document arrives — and asks for the photo instead of
        // the optional-pictures line.
        if ($state['awaiting_document'] ?? false) {
            $body = implode("\n", array_filter([
                $text,
                'Остался обязательный шаг: пришлите фото удостоверения — без него объявление не выйдет. '
                    .'Снимок увидит только наш оператор, в объявлении он не показывается.',
            ]));

            $this->messenger->sendButtons($session->contact, $body, [
                ['id' => self::BUTTON_EDIT, 'title' => self::BUTTON_EDIT_TITLE],
                ['id' => self::BUTTON_MENU, 'title' => self::BUTTON_MENU_TITLE],
            ]);

            return;
        }

        $body = implode("\n", array_filter([
            $text,
            'Проверьте, всё ли верно. Если да — жмите «'.self::BUTTON_SUBMIT_TITLE.'», и объявление уйдёт на проверку.',
            // Last, after the call to action: put ahead of the question the
            // ask would leave it hanging on a request instead of on the
            // collected data.
            $this->hasPhotos($state)
                ? null
                : 'Фотографий пока нет — пришлите снимки, с фото объявление смотрят охотнее.',
        ]));

        $this->messenger->sendButtons($session->contact, $body, [
            ['id' => self::BUTTON_SUBMIT, 'title' => self::BUTTON_SUBMIT_TITLE],
            ['id' => self::BUTTON_EDIT, 'title' => self::BUTTON_EDIT_TITLE],
            ['id' => self::BUTTON_MENU, 'title' => self::BUTTON_MENU_TITLE],
        ]);
    }

    /**
     * Whether the draft already carries dictionary machinery from an
     * earlier turn — the fields are rebuilt from scratch each turn, so
     * their emptiness alone says nothing about what was synced before.
     *
     * @param  array<string, mixed>  $state
     */
    private function draftHasMachineCategories(array $state): bool
    {
        return $state['draft_id'] !== null
            && Listing::query()->whereKey($state['draft_id'])->whereHas('machineCategories')->exists();
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function buildSummary(array $fields, ListingKind $kind): string
    {
        return match ($kind) {
            ListingKind::Rental => collect([
                collect([$fields['category'] ?? null, $fields['brand'] ?? null])->filter()->implode(' '),
                $fields['location'] ?? null,
                $fields['price'] ?? null,
            ])->filter()->implode(', '),
            ListingKind::Repair => collect([
                $fields['person_name'] ?? null,
                $fields['services'] ?? null,
                filled($fields['repair_place'] ?? null) ? RepairPlace::from($fields['repair_place'])->label() : null,
                $fields['location'] ?? null,
                filled($fields['price'] ?? null) ? 'диагностика '.$fields['price'] : null,
            ])->filter()->implode(', '),
            ListingKind::Driver => collect([
                $fields['person_name'] ?? null,
                // The dictionary categories, then the machinery in the
                // driver's own words — the same line Listing::machineryLine()
                // builds for the catalog.
                collect((array) ($fields['machine_categories'] ?? []))
                    ->push(filled($fields['unlisted_machinery'] ?? null)
                        ? $this->unlistedMachineryWord((string) $fields['unlisted_machinery'])
                        : null)
                    ->filter()
                    ->implode(', ') ?: null,
                filled($fields['experience_years'] ?? null) ? 'Стаж '.$fields['experience_years'].' лет' : null,
                filled($fields['licence_type'] ?? null) ? LicenceType::from($fields['licence_type'])->label() : null,
                $fields['location'] ?? null,
                ($fields['travels_to_other_cities'] ?? null) === true ? 'готов выезжать в другие города' : null,
            ])->filter()->implode(', '),
        };
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  list<string>  $missing
     */
    private function clarificationQuestion(array $fields, array $missing, ListingKind $kind): string
    {
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

        // While the name is missing it IS the question. The model's own,
        // aimed at another gap of the questionnaire, would push the
        // introduction to the end of it — or past the clarification limit,
        // into the web form, leaving a listing nobody is named on.
        if (in_array('person_name', $missing, true) && ($fields['clarifying_field'] ?? null) !== 'person_name') {
            return $kind->fallbackQuestions()['person_name'];
        }

        // The model's question is trusted only when its declared target
        // (clarifying_field) is a field the collector itself still counts as
        // missing. The model routinely second-guesses filled fields — asks
        // to narrow several machine categories down to one, re-asks an
        // answer given with a button it never saw — and such a question
        // burns the clarification limit re-asking the answered while the
        // actually missing field never gets asked at all. The model names
        // the location by its own key; the collector misses the resolved
        // location_id.
        $target = $fields['clarifying_field'] ?? null;

        if (filled($fields['clarifying_question'] ?? null)
            && in_array($target === 'location' ? 'location_id' : $target, $missing, true)) {
            return (string) $fields['clarifying_question'];
        }

        return $kind->fallbackQuestions()[$missing[0]] ?? 'Уточните, пожалуйста, детали объявления.';
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
     * @return array<string, mixed>
     */
    private function normalizeState(BotSession $session): array
    {
        return array_merge([
            'kind' => ListingKind::Rental->value,
            'phase' => 'collecting',
            'attempts' => 0,
            'unreadable' => 0,
            'location_lists' => 0,
            'provider_failures' => 0,
            'service_questions' => 0,
            'transcript' => [],
            'fields' => [],
            'draft_id' => null,
            'picked_location_id' => null,
            'picked_location_wording' => null,
            'declined_location_wording' => null,
            'last_question' => null,
            'button_prompts' => [],
            'button_answers' => [],
            'awaiting_document' => false,
            'exit_confirm' => false,
            'unlisted_prompts' => 0,
            'machinery_skipped' => false,
            'unlisted_machinery' => null,
            'stalled_missing' => null,
            'stalled_turns' => 0,
        ], $session->state ?? []);
    }

    /**
     * The listing kind of this dialog. Stored in the state at start();
     * a session started before kinds existed falls back to rental.
     *
     * @param  array<string, mixed>  $state
     */
    private function kind(array $state): ListingKind
    {
        return ListingKind::fromNode($state['kind'] ?? null);
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
