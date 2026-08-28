<?php

namespace App\Services\Bot;

use App\Enums\BotNodeType;
use App\Enums\ListingKind;

/**
 * Read-only accessor over a scenario graph definition.
 *
 * Shape:
 * [
 *     'nodes' => [
 *         ['id' => 'start', 'type' => 'start'],
 *         ['id' => 'n1', 'type' => 'text', 'text' => '...'],
 *         ['id' => 'n2', 'type' => 'buttons', 'text' => '...', 'options' => [['id' => 'o1', 'title' => '...']]],
 *         ['id' => 'n3', 'type' => 'list', 'text' => '...', 'button' => '...', 'options' => [...]],
 *         ['id' => 'n4', 'type' => 'ai'],
 *         ['id' => 'n5', 'type' => 'message', 'text' => '...', 'channel' => 'adaptive',
 *             'template_name' => 'listing_renewal', 'variables' => ['listing.category'],
 *             'options' => [...], 'timeout_hours' => 48],
 *         ['id' => 'n6', 'type' => 'condition', 'condition' => 'listing_published'],
 *         ['id' => 'n7', 'type' => 'action', 'action' => 'renew_listing'],
 *         ['id' => 'n8', 'type' => 'end'],
 *     ],
 *     'edges' => [
 *         ['from' => 'start', 'output' => 'continue', 'to' => 'n1'],
 *         ['from' => 'n2', 'output' => 'option:o1', 'to' => 'n3'],
 *         ['from' => 'n2', 'output' => 'fallback', 'to' => 'n1'],
 *     ],
 * ]
 *
 * Outputs: one per option ("option:{id}"), "continue" (default transition
 * of Start/Text/AI blocks; «Выполнено» of an action), "fallback"
 * («Любая другая фраза»), "yes"/"no", "timeout" and "skipped".
 */
class ScenarioDefinition
{
    /** Safety cap on blocks walked through while resolving a destination. */
    private const int MAX_RESOLVE_STEPS = 20;

    public const string OUTPUT_CONTINUE = 'continue';

    /**
     * The Start block's optional second output: taken instead of "continue"
     * for a contact who has already finished a dialog before, so the
     * scenario can skip the first-time greeting.
     */
    public const string OUTPUT_RETURNING = 'returning';

    public const string OUTPUT_FALLBACK = 'fallback';

    /** The branches of a «Условие» block. */
    public const string OUTPUT_YES = 'yes';

    public const string OUTPUT_NO = 'no';

    /** Fires when a «WhatsApp-сообщение» block got no reply in time. */
    public const string OUTPUT_TIMEOUT = 'timeout';

    /**
     * Срок ожидания, который конструктор предлагает новому блоку
     * «WhatsApp-сообщение», и он же стоит у типовых опросов продления.
     *
     * Это предзаполнение поля, а не поведение по умолчанию: у уже
     * сохранённого блока пустой срок по-прежнему значит «ждать сколько
     * угодно». Предлагается именно сутки — шаг всей проактивной
     * автоматики: и 30-дневный цикл актуальности, и вопрос поставщику
     * живут в суточном ритме.
     */
    public const int SUGGESTED_TIMEOUT_HOURS = 24;

    /**
     * Fires when a «Действие» block's domain precondition no longer holds
     * (the request is already decided, the listing is not published).
     * Success stays on "continue", so older published snapshots keep
     * working unchanged.
     */
    public const string OUTPUT_SKIPPED = 'skipped';

    /**
     * @param  array{nodes?: list<array<string, mixed>>, edges?: list<array<string, mixed>>}  $definition
     */
    public function __construct(private readonly array $definition) {}

    public static function optionOutput(string $optionId): string
    {
        return 'option:'.$optionId;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function node(?string $id): ?array
    {
        if ($id === null) {
            return null;
        }

        foreach ($this->definition['nodes'] ?? [] as $node) {
            if (($node['id'] ?? null) === $id) {
                return $node;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $node
     */
    public function nodeType(?array $node): ?BotNodeType
    {
        return BotNodeType::tryFrom((string) ($node['type'] ?? ''));
    }

    public function startNodeId(): ?string
    {
        foreach ($this->definition['nodes'] ?? [] as $node) {
            if ($this->nodeType($node) === BotNodeType::Start) {
                return $node['id'] ?? null;
            }
        }

        return null;
    }

    /**
     * The node the given output of the given block is wired to.
     */
    public function target(string $nodeId, string $output): ?string
    {
        foreach ($this->definition['edges'] ?? [] as $edge) {
            if (($edge['from'] ?? null) === $nodeId && ($edge['output'] ?? null) === $output) {
                return $edge['to'] ?? null;
            }
        }

        return null;
    }

    /**
     * The block a contact actually ends up standing on after the given
     * output is taken: the engine auto-advances through blocks that wait
     * for nothing (a text block, «Мои объявления»), so a destination can
     * be several «continue» edges past the edge itself. Null when the
     * branch plays out without stopping anywhere.
     *
     * Pure by design — nothing is sent and no session is touched: the walk
     * only answers «where does this lead», which the navigator needs to
     * tell a route into a fresh questionnaire from a route back into the
     * one already paused at that very block.
     */
    public function resolveTarget(string $nodeId, string $output): ?string
    {
        $targetId = $this->target($nodeId, $output);

        // Same order of magnitude as the engine's own step cap, so a
        // mis-published cycle of auto-advancing blocks cannot loop here.
        for ($steps = 0; $steps < self::MAX_RESOLVE_STEPS; $steps++) {
            $node = $this->node($targetId);
            $type = $this->nodeType($node);

            if ($node === null || $type === null) {
                return null;
            }

            if ($type->waitsForInput()) {
                return $targetId;
            }

            $targetId = $this->target((string) $node['id'], self::OUTPUT_CONTINUE);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return list<array{id: string, title: string}>
     */
    public function options(array $node): array
    {
        return array_values($node['options'] ?? []);
    }

    /**
     * Locate the block that owns the option with the given id anywhere in
     * the graph (option ids are unique graph-wide). Lets the engine route a
     * pressed scenario button to its branch even when the contact is no
     * longer standing on that block.
     *
     * @return array{node_id: string, option_id: string}|null
     */
    public function optionOwner(string $optionId): ?array
    {
        foreach ($this->definition['nodes'] ?? [] as $node) {
            foreach ($this->options($node) as $option) {
                if (($option['id'] ?? null) === $optionId) {
                    return ['node_id' => (string) $node['id'], 'option_id' => $optionId];
                }
            }
        }

        return null;
    }

    /**
     * Every option of every buttons/list node in the graph, keyed by option
     * id (ids are unique graph-wide — see optionOwner()). The AI
     * navigator's catalog of possible destinations for a message that
     * matched none of the current menu's own buttons: deliberately not
     * limited to the current node, since a message may name a more
     * specific option nested elsewhere in the graph.
     *
     * @return array<string, array{node_id: string, option_id: string, title: string, context: string}>
     */
    public function menuOptions(): array
    {
        $options = [];

        foreach ($this->definition['nodes'] ?? [] as $node) {
            if (! in_array($this->nodeType($node), [BotNodeType::ButtonMenu, BotNodeType::ListMenu], true)) {
                continue;
            }

            foreach ($this->options($node) as $option) {
                $options[$option['id']] = [
                    'node_id' => (string) $node['id'],
                    'option_id' => (string) $option['id'],
                    'title' => (string) ($option['title'] ?? ''),
                    'context' => (string) ($node['text'] ?? ''),
                ];
            }
        }

        return $options;
    }

    /**
     * Whether the flow must stop at this block for the contact's reply.
     * A «WhatsApp-сообщение» block without buttons is fire-and-forget.
     *
     * @param  array<string, mixed>|null  $node
     */
    public function nodeWaitsForInput(?array $node): bool
    {
        $type = $this->nodeType($node);

        if ($type?->waitsForInput() !== true) {
            return false;
        }

        return $type !== BotNodeType::Message || $this->options($node) !== [];
    }

    /**
     * The reply timeout of a «WhatsApp-сообщение» block, if configured.
     *
     * @param  array<string, mixed>  $node
     */
    public function timeoutHours(array $node): ?int
    {
        $hours = (int) ($node['timeout_hours'] ?? 0);

        return $hours > 0 ? $hours : null;
    }

    /**
     * Compatibility fingerprint of a waiting block: everything the contact
     * relies on while answering it (type, the option set, the AI task and
     * listing kind). After a republication a changed fingerprint means the
     * stored step is incompatible with the new schema — a soft reset, not a
     * silent continuation. Message text tweaks keep the fingerprint intact.
     *
     * @param  array<string, mixed>  $node
     */
    public function nodeFingerprint(array $node): string
    {
        $payload = [
            'type' => $node['type'] ?? null,
            'task' => $node['task'] ?? null,
            'options' => array_map(
                fn (array $option): array => ['id' => $option['id'] ?? null, 'title' => $option['title'] ?? null],
                $this->options($node),
            ),
        ];

        // The rental kind is omitted, not written as 'rental': fingerprints
        // stored by sessions before kinds existed (no kind key in the hash)
        // must keep matching a node that now carries kind=rental — otherwise
        // the first republication would softly reset every waiting dialog.
        $kind = ListingKind::fromNode($node['kind'] ?? null);

        if ($kind !== ListingKind::Rental) {
            $payload['kind'] = $kind->value;
        }

        return md5(json_encode($payload));
    }

    /**
     * Match an inbound message against the block's options: by the pressed
     * button / picked row id, by free text equal to an option title
     * (case-insensitive, trimmed — per the constructor rules), or by a
     * free-text number N picking the N-th option (1-indexed) — title match
     * takes priority, so an option titled with a digit stays reachable by
     * its title.
     *
     * @param  array<string, mixed>  $node
     */
    public function matchOption(array $node, InboundMessage $message): ?string
    {
        $options = $this->options($node);

        if (filled($message->replyId)) {
            foreach ($options as $option) {
                if ($option['id'] === $message->replyId) {
                    return $option['id'];
                }
            }
        }

        $text = mb_strtolower(trim((string) $message->text));

        if ($text === '') {
            return null;
        }

        foreach ($options as $option) {
            if (mb_strtolower(trim($option['title'])) === $text) {
                return $option['id'];
            }
        }

        if (ctype_digit($text)) {
            $index = ((int) $text) - 1;

            if (isset($options[$index])) {
                return $options[$index]['id'];
            }
        }

        return null;
    }
}
