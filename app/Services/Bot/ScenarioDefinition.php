<?php

namespace App\Services\Bot;

use App\Enums\BotNodeType;

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
 * of Start/Text/AI blocks) and "fallback" («Любая другая фраза»).
 */
class ScenarioDefinition
{
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
     * relies on while answering it (type, the option set, the AI task).
     * After a republication a changed fingerprint means the stored step is
     * incompatible with the new schema — a soft reset, not a silent
     * continuation. Message text tweaks keep the fingerprint intact.
     *
     * @param  array<string, mixed>  $node
     */
    public function nodeFingerprint(array $node): string
    {
        return md5(json_encode([
            'type' => $node['type'] ?? null,
            'task' => $node['task'] ?? null,
            'listing_type' => $node['listing_type'] ?? null,
            'options' => array_map(
                fn (array $option): array => ['id' => $option['id'] ?? null, 'title' => $option['title'] ?? null],
                $this->options($node),
            ),
        ]));
    }

    /**
     * Match an inbound message against the block's options: by the pressed
     * button / picked row id, or by free text equal to an option title
     * (case-insensitive, trimmed — per the constructor rules).
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

        return null;
    }
}
