<?php

namespace App\Services\Ai;

use App\Ai\Agents\ListingExtractionAgent;
use App\Enums\AiOutcome;
use App\Enums\ListingMediaType;
use App\Enums\ListingType;
use App\Models\BotSession;
use App\Models\Listing;
use App\Models\ListingMedia;
use App\Services\Bot\InboundMessage;
use App\Enums\AiOperationType;
use App\Services\Ai\Audit\AiAudit;
use App\Services\DereuMediaDownloader;
use App\Services\DereuMessenger;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Transcription;

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
     * Photos attached to one extraction call — enough to recognize the
     * equipment without inflating the prompt.
     */
    private const int MAX_PHOTO_ATTACHMENTS = 5;

    /** @var list<string> */
    private const array REQUIRED_FIELDS = ['category', 'description', 'location', 'price'];

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
    ) {}

    /**
     * @param  array<string, mixed>  $node
     */
    public function start(BotSession $session, array $node): AiOutcome
    {
        $session->state = [
            'phase' => 'collecting',
            'attempts' => 0,
            'transcript' => [],
            'fields' => [],
            'draft_id' => null,
            'listing_type' => $node['listing_type'] ?? null,
        ];
        $session->save();

        $this->messenger->sendText(
            $session->contact,
            'Расскажите, что вы предлагаете: пришлите фото, голосовое или напишите текстом — что это, в каком городе и по какой цене.',
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

        return $this->handleCollecting($session, $state, $message);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function handleCollecting(BotSession $session, array $state, InboundMessage $message): AiOutcome
    {
        // An unreadable message (sticker, empty caption, silent audio) never
        // consumes a clarification attempt — the bot just asks to rephrase.
        if (! $this->intake($session, $state, $message)) {
            $this->persist($session, $state);
            $this->messenger->sendText(
                $session->contact,
                'Не удалось разобрать сообщение. Опишите технику или услугу текстом, голосом или фото.',
            );

            return AiOutcome::InProgress;
        }

        $state['fields'] = $this->extract($session, $state);
        $missing = $this->missingFields($state['fields'], $state);

        if ($missing === []) {
            $draft = $this->ensureDraft($session, $state);
            $draft->update($this->listingAttributes($state));
            $state['phase'] = 'confirming';
            $this->persist($session, $state);
            $this->sendConfirmation($session, $state['fields']);

            return AiOutcome::InProgress;
        }

        if ($state['attempts'] >= self::MAX_CLARIFICATIONS) {
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

        $state['attempts']++;
        $this->persist($session, $state);
        $this->messenger->sendText($session->contact, $this->clarificationQuestion($state['fields'], $missing));

        return AiOutcome::InProgress;
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
        $state['phase'] = 'collecting';

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
        $download = $this->mediaDownloader->download((string) $message->mediaId);
        $draft = $this->ensureDraft($session, $state);

        if ($message->mediaType === ListingMediaType::Audio) {
            $path = "listings/{$draft->id}/audio/".uniqid('', true).'.ogg';
            Storage::disk('public')->put($path, $download['contents']);

            $transcription = $this->audit->run(
                AiOperationType::Transcription,
                fn (): string => trim((string) Transcription::fromBase64(
                    base64_encode($download['contents']),
                    $download['mime_type'],
                )->generate()),
                [
                    'contact_id' => $session->contact_id,
                    'bot_session_id' => $session->id,
                    'listing_id' => $draft->id,
                ],
            );

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
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function extract(BotSession $session, array $state): array
    {
        $expectedType = ListingType::tryFrom((string) ($state['listing_type'] ?? ''));

        $prompt = $state['transcript'] !== []
            ? implode("\n", $state['transcript'])
            : 'Поставщик прислал только фотографии — извлеки из них, что сможешь.';

        return $this->audit->run(
            AiOperationType::ListingExtraction,
            fn (): array => (new ListingExtractionAgent($expectedType))
                ->prompt($prompt, attachments: $this->photoAttachments($state))
                ->toArray(),
            [
                'contact_id' => $session->contact_id,
                'bot_session_id' => $session->id,
                'listing_id' => $state['draft_id'],
            ],
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
            'type' => $this->resolveType($state),
        ]);

        $state['draft_id'] = $draft->id;

        return $draft;
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{type: string, category: ?string, description: ?string, location: ?string, price: ?string}
     */
    private function listingAttributes(array $state): array
    {
        $fields = $state['fields'];

        return [
            'type' => $this->resolveType($state)->value,
            'category' => $fields['category'] ?? null,
            'description' => $fields['description'] ?? null,
            'location' => $fields['location'] ?? null,
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

        $this->messenger->sendButtons($session->contact, $summary."\nВсё верно? Нажмите «".self::BUTTON_SUBMIT_TITLE.'», чтобы отправить объявление на проверку.', [
            ['id' => self::BUTTON_SUBMIT, 'title' => self::BUTTON_SUBMIT_TITLE],
            ['id' => self::BUTTON_EDIT, 'title' => self::BUTTON_EDIT_TITLE],
        ]);
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function buildSummary(array $fields): string
    {
        return collect([$fields['category'] ?? null, $fields['location'] ?? null, $fields['price'] ?? null])
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

        if (filled($fields['clarifying_question'] ?? null)) {
            return (string) $fields['clarifying_question'];
        }

        $questions = [
            'category' => 'Что именно вы предлагаете — какая техника или услуга?',
            'description' => 'Опишите чуть подробнее ваше предложение.',
            'location' => 'В каком городе или районе это доступно?',
            'price' => 'Какая цена или тариф?',
        ];

        return $questions[$missing[0]] ?? 'Уточните, пожалуйста, детали объявления.';
    }

    private function matchesButton(InboundMessage $message, string $id, string $title): bool
    {
        return $message->replyId === $id || mb_strtolower(trim((string) $message->text)) === mb_strtolower($title);
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
            'transcript' => [],
            'fields' => [],
            'draft_id' => null,
            'listing_type' => $node['listing_type'] ?? null,
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
