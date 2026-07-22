<x-filament-panels::page>
    {{-- Filament поставляет предкомпилированный CSS без произвольных tailwind-классов
         кастомных страниц, поэтому раскладка чата описана локальными стилями.
         Палитра и пропорции повторяют WhatsApp Web (светлая и тёмная темы). --}}
    <style>
        .wa { --wa-bg: #efeae2; --wa-panel: #f0f2f5; --wa-out: #d9fdd3; --wa-in: #ffffff; --wa-text: #111b21; --wa-muted: #667781; --wa-border: #e9edef; --wa-read: #53bdeb; }
        .dark .wa { --wa-bg: #0b141a; --wa-panel: #202c33; --wa-out: #005c4b; --wa-in: #111b21; --wa-text: #e9edef; --wa-muted: #8696a0; --wa-border: #2a3942; }

        .wa { display: flex; height: calc(100vh - 13rem); min-height: 28rem; border: 1px solid var(--wa-border); border-radius: .75rem; overflow: hidden; background: var(--wa-in); color: var(--wa-text); font-size: .875rem; }

        .wa-side { display: flex; flex-direction: column; width: clamp(14rem, 38%, 23rem); flex-shrink: 0; border-right: 1px solid var(--wa-border); background: var(--wa-in); }
        .wa-search { padding: .55rem .75rem; border-bottom: 1px solid var(--wa-border); }
        .wa-search input { width: 100%; border: none; outline: none; box-shadow: none; background: var(--wa-panel); color: var(--wa-text); border-radius: .5rem; padding: .45rem .9rem; font-size: .8125rem; }
        .wa-search input::placeholder { color: var(--wa-muted); }
        .wa-dialogs { flex: 1; overflow-y: auto; overflow-x: hidden; }
        .wa-dialog { display: flex; gap: .8rem; align-items: center; width: 100%; min-width: 0; text-align: left; padding: .6rem .8rem; cursor: pointer; }
        .wa-dialog + .wa-dialog { border-top: 1px solid var(--wa-border); }
        .wa-dialog:hover, .wa-dialog.is-selected { background: var(--wa-panel); }
        .wa-dialog-body { display: block; flex: 1; min-width: 0; }

        .wa-ava { display: flex; align-items: center; justify-content: center; width: 3.05rem; height: 3.05rem; border-radius: 9999px; color: #fff; font-weight: 600; font-size: 1.15rem; flex-shrink: 0; user-select: none; }

        .wa-row { display: flex; justify-content: space-between; align-items: baseline; gap: .5rem; }
        .wa-name { font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .wa-time { font-size: .6875rem; color: var(--wa-muted); flex-shrink: 0; }
        .wa-snippet { display: block; font-size: .8125rem; color: var(--wa-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: .1rem; }

        .wa-main { flex: 1; display: flex; flex-direction: column; min-width: 0; background: var(--wa-bg); }
        .wa-header { display: flex; align-items: center; gap: .75rem; padding: .55rem 1rem; background: var(--wa-panel); border-bottom: 1px solid var(--wa-border); }
        .wa-header .wa-ava { width: 2.5rem; height: 2.5rem; font-size: .95rem; }
        .wa-header-info { flex: 1; min-width: 0; }
        .wa-header-name { font-weight: 500; line-height: 1.3; }
        .wa-header-sub { font-size: .75rem; color: var(--wa-muted); }
        .wa-header-stats { text-align: right; font-size: .75rem; color: var(--wa-muted); line-height: 1.4; flex-shrink: 0; }
        .wa-warn { color: #d97706; }

        .wa-thread { flex: 1; overflow-y: auto; padding: .75rem 4rem 1rem; display: flex; flex-direction: column; }
        @media (max-width: 1200px) { .wa-thread { padding: .75rem 1.25rem 1rem; } }
        .wa-chip-center { align-self: center; margin: .55rem 0 .3rem; background: var(--wa-in); color: var(--wa-muted); font-size: .75rem; border: none; border-radius: .5rem; padding: .3rem .8rem; box-shadow: 0 1px .5px rgb(11 20 26 / .13); }
        button.wa-chip-center { cursor: pointer; }

        {{-- flex-shrink: 0 обязателен: overflow:hidden обнуляет автоматическую
             минимальную высоту flex-элемента, и колонка треда иначе сжимает
             пузыри до нечитаемых полосок. --}}
        .wa-bubble { position: relative; align-self: flex-start; flex-shrink: 0; max-width: 65%; min-width: 6.5rem; background: var(--wa-in); border-radius: 7.5px; border-top-left-radius: 0; padding: .375rem .55rem .45rem .6rem; margin-top: .4rem; box-shadow: 0 1px .5px rgb(11 20 26 / .13); overflow-wrap: anywhere; overflow: hidden; }
        .wa-bubble.is-out { align-self: flex-end; background: var(--wa-out); border-top-left-radius: 7.5px; border-top-right-radius: 0; }
        .wa-text { white-space: pre-wrap; line-height: 1.35; }
        .wa-kind { font-size: .75rem; color: var(--wa-muted); margin-bottom: .1rem; }
        .wa-meta { display: flex; justify-content: flex-end; align-items: center; gap: .3rem; margin-top: .15rem; font-size: .6875rem; color: var(--wa-muted); }
        .wa-ticks { letter-spacing: -.12em; font-size: .75rem; }
        .wa-ticks.is-read { color: var(--wa-read); }
        .wa-fail { color: #dc2626; font-size: .75rem; margin-top: .2rem; }

        .wa-reply { font-size: .6875rem; color: var(--wa-muted); font-family: ui-monospace, SFMono-Regular, Menlo, monospace; margin-top: .15rem; }
        .wa-actions { margin: .4rem -.55rem -.45rem -.6rem; border-top: 1px solid rgb(134 150 160 / .28); font-size: .8125rem; }
        .wa-actions > * + * { border-top: 1px solid rgb(134 150 160 / .28); }
        .wa-action { display: flex; align-items: center; justify-content: center; gap: .4rem; padding: .5rem .6rem; color: #0086c3; font-weight: 500; }
        .dark .wa-action { color: var(--wa-read); }
        .wa-list-row { padding: .4rem .6rem .45rem; }
        .wa-list-desc { font-size: .75rem; color: var(--wa-muted); margin-top: .05rem; }
        .wa-url { padding: .35rem .6rem .45rem; font-size: .6875rem; color: var(--wa-muted); font-family: ui-monospace, SFMono-Regular, Menlo, monospace; user-select: all; }
        .dark .wa-list-desc, .dark .wa-url { color: rgb(233 237 239 / .65); }

        .wa-ai { margin-top: .45rem; border-top: 1px dashed rgb(11 20 26 / .16); padding-top: .35rem; font-size: .75rem; color: var(--wa-muted); }
        .dark .wa-ai { border-color: rgb(233 237 239 / .18); }
        .wa-ai summary { cursor: pointer; }
        .wa-ai-op { margin-top: .3rem; color: var(--wa-text); }
        .wa-ai-attempt { margin: .3rem 0 .3rem .6rem; }
        .wa-ai pre { white-space: pre-wrap; overflow-wrap: anywhere; background: rgb(11 20 26 / .06); border-radius: .375rem; padding: .4rem .55rem; margin: .2rem 0 .4rem; max-height: 18rem; overflow-y: auto; color: var(--wa-text); }
        .dark .wa-ai pre { background: rgb(233 237 239 / .08); }
        .wa-danger { color: #dc2626; }
        .wa-mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }

        .wa-empty-side { padding: 1rem; color: var(--wa-muted); font-size: .8125rem; }
        .wa-placeholder { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: .4rem; color: var(--wa-muted); background: var(--wa-panel); text-align: center; padding: 1rem; }
        .wa-placeholder-icon { font-size: 3rem; opacity: .45; }
    </style>

    @php
        $dialogs = $this->dialogs();
        $thread = $this->thread();
        $contact = $this->selectedContact();
        $totals = $contact !== null ? $this->contactTotals() : null;

        $avatarPalette = ['#00a884', '#53bdeb', '#e542a3', '#ffa97a', '#7f66ff', '#fe7c7f', '#91ab01', '#ffbc38'];
        $avatar = function ($person) use ($avatarPalette): array {
            $name = $person->displayName();

            return [
                'letter' => $name !== null ? mb_strtoupper(mb_substr($name, 0, 1)) : '☎',
                'color' => $avatarPalette[$person->id % count($avatarPalette)],
            ];
        };
    @endphp

    <div class="wa" wire:poll.10s>
        <div class="wa-side">
            <div class="wa-search">
                <input
                    type="search"
                    placeholder="Поиск: телефон или имя"
                    wire:model.live.debounce.300ms="search"
                />
            </div>

            <div class="wa-dialogs">
                @forelse ($dialogs as $dialog)
                    @php $ava = $avatar($dialog); @endphp
                    <button
                        type="button"
                        wire:key="dialog-{{ $dialog->id }}"
                        wire:click="selectContact({{ $dialog->id }})"
                        class="wa-dialog {{ $dialog->id === $this->contactId ? 'is-selected' : '' }}"
                    >
                        <span class="wa-ava" style="background: {{ $ava['color'] }}">{{ $ava['letter'] }}</span>
                        <span class="wa-dialog-body">
                            <span class="wa-row">
                                <span class="wa-name">{{ $dialog->displayName() ?? '+'.ltrim($dialog->phone, '+') }}</span>
                                <span class="wa-time">{{ \Illuminate\Support\Carbon::parse($dialog->last_message_at)->format('d.m H:i') }}</span>
                            </span>
                            <span class="wa-snippet">{{ \Illuminate\Support\Str::limit($dialog->last_message_text ?? '—', 45) }}</span>
                        </span>
                    </button>
                @empty
                    <div class="wa-empty-side">Диалогов нет.</div>
                @endforelse
            </div>
        </div>

        <div class="wa-main">
            @if ($contact === null)
                <div class="wa-placeholder">
                    <div class="wa-placeholder-icon">💬</div>
                    <div>Выберите диалог слева, чтобы посмотреть переписку.</div>
                </div>
            @else
                @php $ava = $avatar($contact); @endphp
                <div class="wa-header">
                    <span class="wa-ava" style="background: {{ $ava['color'] }}">{{ $ava['letter'] }}</span>
                    <div class="wa-header-info">
                        <div class="wa-header-name">{{ $contact->displayName() ?? '+'.ltrim($contact->phone, '+') }}</div>
                        @if ($contact->displayName() !== null)
                            <div class="wa-header-sub">+{{ ltrim($contact->phone, '+') }}</div>
                        @endif
                    </div>
                    <div class="wa-header-stats" title="Суммы — оценка по сохранённым тарифам; шаблоны считаются только доставленные/прочитанные.">
                        <div>
                            AI: ${{ $totals['ai_cost'] }}@if ($totals['ai_unknown'] > 0) <span class="wa-warn">(без тарифа: {{ $totals['ai_unknown'] }})</span>@endif
                            · Шаблоны: ${{ $totals['template_cost'] }}@if ($totals['template_unknown'] > 0) <span class="wa-warn">(без тарифа: {{ $totals['template_unknown'] }})</span>@endif
                        </div>
                        <div>вх: {{ $totals['inbound'] }} · исх: {{ $totals['outbound'] }} · шаблонов: {{ $totals['templates'] }} · ошибок: {{ $totals['failed'] }}</div>
                    </div>
                </div>

                {{-- wire:key пересоздаёт контейнер при смене диалога, чтобы x-init
                     снова прокрутил тред к свежим сообщениям. --}}
                <div class="wa-thread" wire:key="thread-{{ $this->contactId }}" x-data x-init="$el.scrollTop = $el.scrollHeight">
                    @if ($thread['has_older'])
                        <button type="button" class="wa-chip-center" wire:click="loadOlder">Показать более ранние</button>
                    @endif

                    @php $previousDay = null; @endphp

                    @forelse ($thread['messages'] as $message)
                        @php $day = $message->created_at->format('d.m.Y'); @endphp
                        @if ($day !== $previousDay)
                            <div class="wa-chip-center" wire:key="day-{{ $message->id }}">{{ $day }}</div>
                            @php $previousDay = $day; @endphp
                        @endif

                        <div
                            wire:key="msg-{{ $message->id }}"
                            class="wa-bubble {{ $message->direction === \App\Enums\ChannelDirection::Outbound ? 'is-out' : '' }}"
                        >
                            @php
                                $extras = $this->messageExtras($message);
                                $chip = $extras['chip'] ?? match ($message->type) {
                                    'text' => null,
                                    'image' => '📷 Фото',
                                    'audio' => '🎤 Голосовое',
                                    'video' => '🎬 Видео',
                                    'document' => '📄 Документ',
                                    'sticker' => '🧷 Стикер',
                                    'location' => '📍 Локация',
                                    'interactive' => '🧩 Интерактив',
                                    'button' => '🔘 Кнопка',
                                    'template' => '📋 Шаблон',
                                    default => $message->type,
                                };
                            @endphp

                            @if ($chip !== null)
                                <div class="wa-kind">{{ $chip }}</div>
                            @endif

                            @if ($extras['body'] !== null)
                                <div class="wa-text">{{ $extras['body'] }}</div>
                            @elseif (filled($message->text))
                                <div class="wa-text">{{ $message->text }}</div>
                            @endif

                            @if ($extras['reply_id'] !== null)
                                <div class="wa-reply">id: {{ $extras['reply_id'] }}</div>
                            @endif

                            @foreach ($extras['errors'] as $error)
                                <div class="wa-fail">{{ $error }}</div>
                            @endforeach

                            @if ($message->status === \App\Enums\ChannelMessageStatus::Failed && filled($message->failure_reason))
                                <div class="wa-fail">Не доставлено: {{ $message->failure_reason }}</div>
                            @endif

                            <div class="wa-meta">
                                @if ($message->type === 'template' && $message->cost_status !== null)
                                    @if ($message->cost_status === \App\Enums\AiCostStatus::Estimated)
                                        <span>${{ number_format((float) $message->estimated_cost_usd, 4) }} · {{ $message->pricing_snapshot['category'] ?? '' }}</span>
                                    @else
                                        <span class="wa-warn">тариф не задан</span>
                                    @endif
                                @endif

                                <span>{{ $message->created_at->format('H:i') }}</span>

                                @if ($message->direction === \App\Enums\ChannelDirection::Outbound)
                                    <span class="wa-ticks {{ $message->status === \App\Enums\ChannelMessageStatus::Read ? 'is-read' : '' }}" title="{{ $message->status->value }}">
                                        @switch($message->status)
                                            @case(\App\Enums\ChannelMessageStatus::Queued) 🕓 @break
                                            @case(\App\Enums\ChannelMessageStatus::Sent) ✓ @break
                                            @case(\App\Enums\ChannelMessageStatus::Delivered) ✓✓ @break
                                            @case(\App\Enums\ChannelMessageStatus::Read) ✓✓ @break
                                            @case(\App\Enums\ChannelMessageStatus::Failed) <span class="wa-danger">⚠</span> @break
                                        @endswitch
                                    </span>
                                @endif
                            </div>

                            @if ($extras['list_button'] !== null || $extras['buttons'] !== [] || $extras['rows'] !== [])
                                <div class="wa-actions">
                                    @if ($extras['list_button'] !== null)
                                        <div class="wa-action">☰ {{ $extras['list_button'] }}</div>
                                    @endif

                                    {{-- URL-кнопки — персональные подписанные ссылки контакта
                                         (кабинет, каталог): адрес показывается текстом, живого
                                         перехода из админки от имени контакта нет намеренно. --}}
                                    @foreach ($extras['buttons'] as $button)
                                        @if ($button['url'] !== null)
                                            <div class="wa-action">🔗 {{ $button['title'] ?? 'Ссылка' }}</div>
                                            <div class="wa-url">{{ $button['url'] }}</div>
                                        @else
                                            <div
                                                class="wa-action {{ $button['title'] === null ? 'wa-mono' : '' }}"
                                                @if ($button['id'] !== null) title="id: {{ $button['id'] }}" @endif
                                            >↩ {{ $button['title'] ?? $button['id'] }}</div>
                                        @endif
                                    @endforeach

                                    @foreach ($extras['rows'] as $row)
                                        <div class="wa-list-row" @if ($row['id'] !== null) title="id: {{ $row['id'] }}" @endif>
                                            <div>{{ $row['title'] }}</div>
                                            @if (filled($row['description']))
                                                <div class="wa-list-desc">{{ $row['description'] }}</div>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            @endif

                            @if ($message->aiOperations->isNotEmpty())
                                {{-- Состояние раскрытия живёт в Alpine: wire:poll морфит DOM и
                                     снимает атрибут open, а x-data переживает обновления. --}}
                                <details class="wa-ai" x-data="{ open: false }" :open="open" @toggle="open = $event.target.open">
                                    <summary>🤖 AI: {{ $message->aiOperations->count() }} операц.</summary>

                                    @foreach ($message->aiOperations as $operation)
                                        <div class="wa-ai-op" wire:key="op-{{ $operation->id }}">
                                            <span style="font-weight: 500">{{ $operation->operation->getLabel() }}</span>
                                            · {{ match ($operation->status) {
                                                \App\Enums\AiOperationStatus::Running => 'выполняется',
                                                \App\Enums\AiOperationStatus::Completed => 'успешно',
                                                \App\Enums\AiOperationStatus::Failed => 'ошибка',
                                            } }}
                                            @if (filled($operation->error))
                                                <span class="wa-danger">{{ $operation->error }}</span>
                                            @endif

                                            @foreach ($operation->attempts as $attempt)
                                                <div class="wa-ai-attempt" wire:key="attempt-{{ $attempt->id }}">
                                                    <div>
                                                        <span class="wa-mono">{{ $attempt->provider }}/{{ $attempt->model }}</span>
                                                        · токены: {{ number_format((int) $attempt->input_tokens) }} вх / {{ number_format((int) $attempt->output_tokens) }} исх
                                                        @if ($attempt->latency_ms !== null) · {{ $attempt->latency_ms }} мс @endif
                                                        · @if ($attempt->cost_status === \App\Enums\AiCostStatus::Estimated) ${{ number_format((float) $attempt->estimated_cost_usd, 4) }} @else <span class="wa-warn">без тарифа</span> @endif
                                                        @if (filled($attempt->error)) <span class="wa-danger">{{ $attempt->error }}</span> @endif
                                                    </div>
                                                    <details x-data="{ open: false }" :open="open" @toggle="open = $event.target.open">
                                                        <summary>Промпт и ответ</summary>
                                                        <div>Промпт:</div>
                                                        <pre>{{ $attempt->prompt ?? '—' }}</pre>
                                                        <div>Ответ:</div>
                                                        <pre>{{ $attempt->response ?? '—' }}</pre>
                                                    </details>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endforeach
                                </details>
                            @endif
                        </div>
                    @empty
                        <div class="wa-chip-center">Сообщений нет.</div>
                    @endforelse
                </div>
            @endif
        </div>
    </div>
</x-filament-panels::page>
