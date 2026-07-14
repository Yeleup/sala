<?php

namespace App\Services\Ai;

use App\Enums\AiOutcome;
use App\Enums\BotScenarioTrigger;
use App\Models\BotScenario;
use App\Models\BotSession;
use App\Models\CustomerRequest;
use App\Models\Listing;
use App\Services\Bot\InboundMessage;
use App\Services\Bot\ScenarioRunner;
use App\Services\CustomerRequestNotifier;
use App\Services\DereuMessenger;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The customer branch of the AI module (docs/modules/ai-assistant.md):
 * asks what the customer needs, matches the free-text query against
 * published listings, offers the ranked results as a WhatsApp list, and
 * turns the chosen option into a customer request with a supplier
 * notification. Equipment is never locked by a request.
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

    public function __construct(
        private readonly DereuMessenger $messenger,
        private readonly ListingMatcher $matcher,
        private readonly ScenarioRunner $runner,
        private readonly CustomerRequestNotifier $notifier,
    ) {}

    /**
     * @param  array<string, mixed>  $node
     */
    public function start(BotSession $session, array $node): AiOutcome
    {
        $session->state = ['phase' => 'searching', 'attempts' => 0, 'query' => null, 'offered' => []];
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
        $state += ['phase' => 'searching', 'attempts' => 0, 'query' => null, 'offered' => []];

        if ($state['phase'] === 'choosing') {
            $chosen = $this->matchChoice($state['offered'], $message);

            if ($chosen !== null) {
                return $this->placeRequest($session, $state, $chosen);
            }
        }

        return $this->search($session, $state, $message);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    protected function search(BotSession $session, array $state, InboundMessage $message): AiOutcome
    {
        $query = trim((string) $message->text);

        if ($query === '') {
            $this->persist($session, $state);
            $this->messenger->sendText(
                $session->contact,
                'Опишите, пожалуйста, текстом: что нужно и в каком городе.',
            );

            return AiOutcome::InProgress;
        }

        $matches = $this->matcher->match($query);

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

            $this->persist($session, $state);
            $this->messenger->sendText(
                $session->contact,
                'По запросу ничего не нашлось. Попробуйте описать иначе: вид техники или услуги и город.',
            );

            return AiOutcome::InProgress;
        }

        $state['phase'] = 'choosing';
        $state['query'] = $query;
        $state['offered'] = $matches->pluck('id')->all();
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
                $listing->category ?: 'объявление',
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

        $description = implode(' · ', array_filter([$listing->location, $listing->price]));

        if ($description !== '') {
            $row['description'] = Str::limit($description, self::ROW_DESCRIPTION_LIMIT - 1);
        }

        return $row;
    }

    protected function rowTitle(Listing $listing): string
    {
        return Str::limit($listing->category ?: 'Объявление №'.$listing->id, self::ROW_TITLE_LIMIT - 1);
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
