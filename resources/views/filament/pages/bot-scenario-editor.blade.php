<x-filament-panels::page>
    @php $scenario = $this->scenario; @endphp

    <div class="bse-tabs">
        @foreach ($this->scenarios as $item)
            <button type="button"
                    wire:click="selectScenario({{ $item->id }})"
                    class="bse-tab {{ $item->id === $scenario->id ? 'bse-tab-active' : '' }}">
                <span class="bse-tab-name">{{ $item->name }}</span>
                <span class="bse-tab-trigger">{{ $item->trigger->label() }}</span>
            </button>
        @endforeach

        <details class="bse-create">
            <summary class="bse-tab">+ Новый сценарий</summary>
            <div class="bse-create-form">
                <input type="text" class="bse-input" placeholder="Название сценария" wire:model="newScenarioName">
                <select class="bse-input" wire:model="newScenarioTrigger">
                    @foreach (\App\Enums\BotScenarioTrigger::cases() as $trigger)
                        @if ($trigger->isRunBased())
                            <option value="{{ $trigger->value }}">{{ $trigger->label() }}</option>
                        @endif
                    @endforeach
                </select>
                <button type="button" class="bse-btn bse-btn-primary" wire:click="createScenario">Создать</button>
                <p class="bse-note">Главный диалог один; новые сценарии — автосценарии на системные события. Триггер задаётся при создании.</p>
            </div>
        </details>
    </div>

    <div class="flex flex-wrap items-center gap-2 text-sm">
        <x-filament::badge color="info">{{ $scenario->trigger->label() }}</x-filament::badge>

        @if ($scenario->isPublished())
            <x-filament::badge color="success">Опубликована версия {{ $scenario->published_version }}</x-filament::badge>
            <span class="text-gray-500 dark:text-gray-400">от {{ $scenario->published_at->format('d.m.Y H:i') }}</span>
        @else
            <x-filament::badge color="gray">Сценарий ещё не опубликован</x-filament::badge>
        @endif

        @if ($scenario->hasUnpublishedChanges())
            <x-filament::badge color="warning">Есть неопубликованные изменения</x-filament::badge>
        @endif
    </div>

    <div wire:ignore wire:key="bse-{{ $scenario->id }}"
         x-data="botScenarioEditor(@js($scenario->draft_definition), @js($this->editorConfig))"
         class="bse" x-bind:class="{ 'bse-dragging': drag }">
        <div class="bse-toolbar">
            <span class="bse-toolbar-label">Добавить блок:</span>
            <button type="button" class="bse-btn" x-on:click="addNode('text')">@svg('heroicon-o-chat-bubble-bottom-center-text', 'bse-btn-icon bse-icon--text') Текст</button>
            <template x-if="!config.runBased">
                <span style="display: contents">
                    <button type="button" class="bse-btn" x-on:click="addNode('buttons')">@svg('heroicon-o-cursor-arrow-rays', 'bse-btn-icon bse-icon--buttons') Меню (кнопки)</button>
                    <button type="button" class="bse-btn" x-on:click="addNode('list')">@svg('heroicon-o-queue-list', 'bse-btn-icon bse-icon--list') Список</button>
                    <button type="button" class="bse-btn" x-on:click="addNode('ai')">@svg('heroicon-o-sparkles', 'bse-btn-icon bse-icon--ai') Запрос ввода (AI)</button>
                </span>
            </template>
            <template x-if="config.runBased">
                <span style="display: contents">
                    <button type="button" class="bse-btn" x-on:click="addNode('message')">@svg('heroicon-o-paper-airplane', 'bse-btn-icon bse-icon--message') WhatsApp-сообщение</button>
                    <button type="button" class="bse-btn" x-on:click="addNode('condition')">@svg('heroicon-o-scale', 'bse-btn-icon bse-icon--condition') Условие</button>
                    <button type="button" class="bse-btn" x-on:click="addNode('action')">@svg('heroicon-o-bolt', 'bse-btn-icon bse-icon--action') Действие</button>
                </span>
            </template>
            <button type="button" class="bse-btn" x-on:click="addNode('my_listings')">@svg('heroicon-o-arrow-top-right-on-square', 'bse-btn-icon bse-icon--my_listings') Мои объявления (CTA)</button>
            <button type="button" class="bse-btn" x-on:click="addNode('end')">@svg('heroicon-o-flag', 'bse-btn-icon bse-icon--end') Завершение</button>

            <span class="bse-toolbar-spring"></span>

            <input type="search" class="bse-input bse-search" placeholder="Поиск по блокам…"
                   x-model="search" x-on:keydown.enter.prevent="focusSearch()">
            <button type="button" class="bse-btn" x-on:click="focusSearch()" title="Показать найденный блок">Найти</button>

            <span class="bse-toolbar-sep"></span>

            <button type="button" class="bse-btn" x-on:click="zoomOut()" title="Уменьшить">−</button>
            <span class="bse-zoom" x-text="Math.round(scale * 100) + '%'"></span>
            <button type="button" class="bse-btn" x-on:click="zoomIn()" title="Увеличить">+</button>
            <button type="button" class="bse-btn" x-on:click="fitView()" title="Показать всю схему">⌖ Центрировать</button>
            <button type="button" class="bse-btn" x-show="! layoutBackup" x-on:click="autoLayout()"
                    title="Разложить блоки по шагам слева направо">Выровнять схему</button>
            <button type="button" class="bse-btn" x-show="layoutBackup" x-on:click="restoreLayout()"
                    title="Вернуть блоки на прежние места">Вернуть расстановку</button>
        </div>

        <div class="bse-hintbar">
            <template x-if="linking">
                <span class="bse-hint-active">Теперь кликните по блоку, к которому ведёт этот выход. Esc — отмена.</span>
            </template>
            <template x-if="!linking">
                <span>Как соединить блоки: кликните (или потяните) кружок справа от выхода, затем кликните по следующему блоку. Протяните связь в пустое место, чтобы разорвать её.</span>
            </template>
        </div>

        <div class="bse-body">
            <div class="bse-viewport" x-ref="viewport"
                 x-on:pointerdown.self="startPan($event)"
                 x-on:wheel.prevent="onWheel($event)"
                 x-bind:style="`background-position: ${pan.x}px ${pan.y}px; background-size: ${24 * scale}px ${24 * scale}px`">
                <div class="bse-world" x-bind:style="`transform: translate(${pan.x}px, ${pan.y}px) scale(${scale})`">
                    {{-- <template x-for> внутри <svg> браузеры не поддерживают,
                         поэтому каждая связь — свой абсолютный svg-оверлей:
                         это даёт цвет, подпись и подсветку каждой ветке. --}}
                    <template x-for="edge in edges" :key="edge.from + ':' + edge.output">
                        <svg class="bse-edges bse-edge-svg" x-bind:class="edgeClasses(edge)">
                            <path class="bse-edge" x-bind:d="edgePath(edge)"/>
                            <path class="bse-arrow" x-bind:d="arrowPath(edge)"/>
                            <text class="bse-edge-label" text-anchor="middle"
                                  x-show="scale >= 0.6 && edgeLabel(edge)"
                                  x-bind:x="edgeMid(edge)?.x ?? 0"
                                  x-bind:y="(edgeMid(edge)?.y ?? 0) - 7"
                                  x-text="edgeLabel(edge)"></text>
                        </svg>
                    </template>
                    <svg class="bse-edges">
                        <path class="bse-edge bse-edge-temp" x-show="linkLine"
                              x-bind:d="linkLine ? `M ${linkLine.x1} ${linkLine.y1} C ${linkLine.x1 + 60} ${linkLine.y1}, ${linkLine.x2 - 60} ${linkLine.y2}, ${linkLine.x2} ${linkLine.y2}` : ''"/>
                    </svg>

                    <template x-for="node in nodes" :key="node.id">
                        <div class="bse-node" x-bind:data-node-id="node.id"
                             x-bind:class="{ 'bse-selected': selectedId === node.id, 'bse-hit': matches(node), 'bse-node-start': node.type === 'start', 'bse-link-target': !!linking, ['bse-type-' + node.type]: true }"
                             x-bind:style="`left: ${node.x}px; top: ${node.y}px`"
                             x-on:pointerdown.stop="startNodeDrag(node, $event)">
                            <div class="bse-node-header">
                                <span class="bse-node-in"></span>
                                <span class="bse-step" x-text="stepNumber(node.id)"
                                      x-bind:title="stepNumber(node.id) === '•' ? 'Блок не связан со «Стартом»' : 'Шаг ' + stepNumber(node.id)"></span>
                                @foreach (['start' => 'play', 'text' => 'chat-bubble-bottom-center-text', 'buttons' => 'cursor-arrow-rays', 'list' => 'queue-list', 'ai' => 'sparkles', 'my_listings' => 'arrow-top-right-on-square', 'message' => 'paper-airplane', 'condition' => 'scale', 'action' => 'bolt', 'end' => 'flag'] as $nodeType => $icon)
                                    @svg('heroicon-o-'.$icon, 'bse-node-icon', ['x-show' => "node.type === '{$nodeType}'"])
                                @endforeach
                                <span class="bse-node-type" x-text="typeLabels[node.type] ?? node.type"></span>
                            </div>
                            <div class="bse-node-text" x-show="node.type !== 'start'"
                                 x-text="nodeSummary(node)"></div>
                            <template x-for="out in outputsOf(node)" :key="out.key">
                                <div class="bse-out">
                                    <span class="bse-out-label" x-text="out.label"></span>
                                    <span class="bse-port"
                                          x-bind:class="{ 'bse-port-connected': isConnected(node.id, out.key), ['bse-port--' + edgeGroup(out.key)]: true }"
                                          x-on:pointerdown.stop.prevent="startLink(node, out.key, $event)"
                                          title="Кликните или потяните, чтобы соединить со следующим блоком"></span>
                                </div>
                            </template>
                        </div>
                    </template>
                </div>

                <div class="bse-legend">
                    <template x-if="config.runBased">
                        <span class="bse-legend-item"><i class="bse-legend-line bse-legend-line--yes"></i>Выполнено / Да</span>
                    </template>
                    <template x-if="config.runBased">
                        <span class="bse-legend-item"><i class="bse-legend-line bse-legend-line--no"></i>Не выполнено / Нет</span>
                    </template>
                    <span class="bse-legend-item"><i class="bse-legend-line bse-legend-line--option"></i>Кнопка</span>
                    <template x-if="config.runBased">
                        <span class="bse-legend-item"><i class="bse-legend-line bse-legend-line--timeout"></i>Таймаут</span>
                    </template>
                    <template x-if="!config.runBased">
                        <span class="bse-legend-item"><i class="bse-legend-line bse-legend-line--fallback"></i>Любая другая фраза</span>
                    </template>
                </div>
            </div>

            <aside class="bse-panel">
                <template x-if="selected">
                    <div class="bse-panel-body">
                        <div class="bse-panel-title" x-text="typeLabels[selected.type] ?? selected.type"></div>

                        <template x-if="!['start', 'ai', 'condition', 'end'].includes(selected.type)
                            && !(selected.type === 'message' && selected.channel !== 'session')">
                            <label class="bse-field">
                                <span>Текст сообщения</span>
                                <textarea class="bse-input" rows="4" x-model="selected.text"
                                          placeholder="Что увидит пользователь"></textarea>
                                <template x-if="config.runBased && selected.type !== 'action'">
                                    <p class="bse-note">Можно вставлять данные: @{{listing.title}}, @{{request.query}} и другие переменные.</p>
                                </template>
                                <template x-if="selected.type === 'action'">
                                    <p class="bse-note">Используется действием «Отправить CTA-ссылку на кабинет» как текст сообщения; для остальных действий не нужен.</p>
                                </template>
                            </label>
                        </template>

                        <template x-if="selected.type === 'ai'">
                            <div class="bse-field">
                                <p class="bse-note" x-text="selected.task === 'customer_search'
                                    ? 'Управление передаётся AI-ассистенту: он спрашивает, что нужно заказчику, подбирает опубликованные объявления и отправляет заявку выбранному поставщику. После завершения пользователь идёт по выходу «Продолжить».'
                                    : 'Управление передаётся AI-ассистенту: он собирает объявление из текста, аудио и фото поставщика, задаёт уточняющие вопросы и создаёт черновик. После завершения пользователь идёт по выходу «Продолжить».'"></p>
                                <span>Задача AI</span>
                                <select class="bse-input" x-model="selected.task">
                                    <option value="collect_listing">Сбор объявления поставщика</option>
                                    <option value="customer_search">Поиск для заказчика</option>
                                </select>
                                <template x-if="selected.task !== 'customer_search'">
                                    <label class="bse-field" style="margin-top: 0.5rem;">
                                        <span>Тип объявления в этой ветке</span>
                                        <select class="bse-input" x-model="selected.listing_type">
                                            <option value="">Определять автоматически</option>
                                            <option value="equipment">Техника</option>
                                            <option value="service">Услуга</option>
                                        </select>
                                    </label>
                                </template>
                            </div>
                        </template>

                        <template x-if="selected.type === 'message'">
                            <div class="bse-field">
                                <span>Канал отправки</span>
                                <select class="bse-input" x-model="selected.channel">
                                    <option value="adaptive">Адаптивно: сессия или шаблон</option>
                                    <option value="session">Только сессионное</option>
                                    <option value="template">Только шаблон</option>
                                </select>
                                <p class="bse-note">Текст сообщения задаёт шаблон Meta. Адаптивно: вне 24-часового окна уходит платный шаблон, в открытое окно — тот же текст бесплатным сессионным сообщением.</p>

                                <template x-if="selected.channel !== 'session'">
                                    <label class="bse-field" style="margin-top: 0.5rem;">
                                        <span>Шаблон Meta — задаёт текст сообщения</span>
                                        <select class="bse-input" x-model="selected.template_name">
                                            <option value="">— не выбран —</option>
                                            <template x-for="t in config.templates" :key="t.name">
                                                <option x-bind:value="t.name" x-text="t.label"></option>
                                            </template>
                                        </select>
                                        <template x-if="(config.templates.find(t => t.name === selected.template_name)?.body ?? '') !== ''">
                                            <div class="bse-template-preview">
                                                <p class="bse-note" style="margin-bottom: 0.25rem;">Текст зафиксирован в Meta — для изменения зарегистрируйте новый шаблон в реестре:</p>
                                                <p class="bse-template-body" x-text="config.templates.find(t => t.name === selected.template_name)?.body"></p>
                                            </div>
                                        </template>
                                    </label>
                                </template>

                                <template x-if="selected.channel !== 'session'">
                                    <div class="bse-field" style="margin-top: 0.5rem;"
                                         x-effect="syncTemplateVariables(selected)">
                                        <span x-text="`Переменные шаблона (${(selected.variables ?? []).length})`"></span>
                                        <template x-for="(variable, index) in (selected.variables ?? [])" :key="index">
                                            <div class="bse-option-row">
                                                <span class="bse-var-index" x-text="'{'+'{'+(index + 1)+'}'+'}'"></span>
                                                <select class="bse-input" x-model="selected.variables[index]">
                                                    <option value="">— не выбрано —</option>
                                                    <template x-for="v in config.variables" :key="v.value">
                                                        <option x-bind:value="v.value" x-text="v.label"></option>
                                                    </template>
                                                </select>
                                            </div>
                                        </template>
                                        <template x-if="(selected.variables ?? []).length === 0">
                                            <p class="bse-note">У выбранного шаблона нет переменных @{{n}} — сопоставлять нечего.</p>
                                        </template>
                                        <template x-if="(selected.variables ?? []).length > 0">
                                            <p class="bse-note">Строки созданы автоматически по числу @{{n}} в теле шаблона — выберите, какие данные подставлять в каждую.</p>
                                        </template>
                                    </div>
                                </template>

                                <label class="bse-field" style="margin-top: 0.5rem;">
                                    <span>Таймаут ожидания ответа (часов)</span>
                                    <input type="number" class="bse-input" min="0" x-model.number="selected.timeout_hours">
                                    <p class="bse-note">0 — ждать без ограничения. По истечении срока запуск идёт по выходу «Таймаут» (или тихо завершается, если выход не подключен).</p>
                                </label>
                            </div>
                        </template>

                        <template x-if="selected.type === 'condition'">
                            <label class="bse-field">
                                <span>Условие</span>
                                <select class="bse-input" x-model="selected.condition">
                                    <template x-for="c in config.conditions" :key="c.value">
                                        <option x-bind:value="c.value" x-text="c.label"></option>
                                    </template>
                                </select>
                                <p class="bse-note">Проверяется в момент прохождения блока — в том числе когда ответ пришёл спустя дни.</p>
                            </label>
                        </template>

                        <template x-if="selected.type === 'action'">
                            <label class="bse-field">
                                <span>Действие</span>
                                <select class="bse-input" x-model="selected.action"
                                        x-on:change="onActionChanged(selected)">
                                    <template x-for="a in config.actions" :key="a.value">
                                        <option x-bind:value="a.value" x-text="a.label"></option>
                                    </template>
                                </select>
                                <p class="bse-note" x-show="actionConfig(selected)?.skippable"
                                   x-text="`Если действие уже нельзя выполнить, запуск идёт по выходу «${actionConfig(selected)?.skipped_label}»; без связи — тихо завершается.`"></p>
                                <p class="bse-note" x-show="! actionConfig(selected)?.skippable">Действие выполняется по возможности и всегда идёт дальше по «Продолжить».</p>
                            </label>
                        </template>

                        <template x-if="selected.type === 'end'">
                            <p class="bse-note">Диалог или запуск сценария завершается на этом блоке.</p>
                        </template>

                        <template x-if="selected.type === 'list'">
                            <label class="bse-field">
                                <span>Надпись на кнопке списка</span>
                                <input type="text" class="bse-input" x-model="selected.button" maxlength="20">
                            </label>
                        </template>

                        <template x-if="['buttons', 'list', 'message'].includes(selected.type)">
                            <div class="bse-field">
                                <span x-text="selected.type === 'list'
                                    ? `Элементы списка (${selected.options.length} из ${optionLimit.list})`
                                    : `Кнопки (${selected.options.length} из ${optionLimit[selected.type]})`"></span>
                                <template x-for="opt in selected.options" :key="opt.id">
                                    <div class="bse-option-row">
                                        <input type="text" class="bse-input" x-model="opt.title"
                                               x-bind:maxlength="selected.type === 'list' ? 24 : 20"
                                               placeholder="Название">
                                        <button type="button" class="bse-btn bse-btn-danger"
                                                x-on:click="removeOption(selected, opt.id)" title="Удалить вариант">✕</button>
                                    </div>
                                </template>
                                <button type="button" class="bse-btn"
                                        x-on:click="addOption(selected)"
                                        x-bind:disabled="selected.options.length >= optionLimit[selected.type]">
                                    + Добавить вариант
                                </button>
                                <p class="bse-note" x-show="selected.type !== 'message'">Лимит WhatsApp: до 3 кнопок (до 20 символов) или до 10 элементов списка (до 24 символов).</p>
                                <p class="bse-note" x-show="selected.type === 'message'">До 3 кнопок (до 20 символов). У выбранного шаблона должно быть столько же кнопок быстрого ответа. Без кнопок блок отправляет сообщение и идёт дальше по «Продолжить».</p>
                            </div>
                        </template>

                        <div class="bse-field">
                            <span>Переходы (куда ведут выходы)</span>
                            <template x-for="out in outputsOf(selected)" :key="selected.id + out.key">
                                <label class="bse-transition">
                                    <span class="bse-note" x-text="out.label"></span>
                                    <select class="bse-input"
                                            x-on:change="setTarget(selected.id, out.key, $event.target.value)"
                                            x-effect="const v = targetOf(selected.id, out.key) ?? ''; $nextTick(() => { $el.value = v })">
                                        <option value="">— не подключено —</option>
                                        <template x-for="n in nodes" :key="n.id">
                                            <option x-bind:value="n.id" x-text="nodeLabel(n)"></option>
                                        </template>
                                    </select>
                                </label>
                            </template>
                        </div>

                        <template x-if="selected.type !== 'start'">
                            <button type="button" class="bse-btn bse-btn-danger bse-btn-block"
                                    x-on:click="removeNode(selected.id)">
                                Удалить блок
                            </button>
                        </template>

                        <template x-if="selected.type === 'start'">
                            <p class="bse-note">Точка входа: с этого блока начинается каждый новый диалог или запуск.
                                Блок единственный и не удаляется.</p>
                        </template>
                    </div>
                </template>

                <template x-if="!selected">
                    <div class="bse-panel-body">
                        <div class="bse-check">
                            <button type="button" class="bse-btn" x-on:click="runCheck()" x-bind:disabled="busy">Проверить сценарий</button>
                            <template x-if="checkResult && ! checkResult.errors.length && ! checkResult.warnings.length">
                                <p class="bse-check-ok">✓ Готов к публикации</p>
                            </template>
                            <template x-for="(issue, i) in (checkResult?.errors ?? [])" :key="'e' + i">
                                <p class="bse-check-error" x-on:click="issue.node_id && focusNode(issue.node_id)"
                                   x-bind:class="{ 'bse-check-link': issue.node_id }" x-text="issue.message"></p>
                            </template>
                            <template x-for="(issue, i) in (checkResult?.warnings ?? [])" :key="'w' + i">
                                <p class="bse-check-warning" x-on:click="issue.node_id && focusNode(issue.node_id)"
                                   x-bind:class="{ 'bse-check-link': issue.node_id }" x-text="issue.message"></p>
                            </template>
                        </div>

                        <div class="bse-outline">
                            <p class="bse-outline-title">Маршрут сценария</p>
                            <template x-for="row in outline()" :key="row.id">
                                <button type="button" class="bse-outline-row"
                                        x-bind:class="{ 'bse-outline-unreachable': row.unreachable }"
                                        x-bind:style="`padding-left: ${row.depth * 0.75}rem`"
                                        x-on:click="focusNode(row.id)">
                                    <span class="bse-outline-via" x-show="row.via" x-text="'↳ ' + row.via + ' → '"></span>
                                    <span x-text="row.unreachable ? row.label + ' — не связан со «Стартом»' : row.label"></span>
                                </button>
                            </template>
                        </div>

                        <p class="bse-note">Выберите блок на холсте (или в маршруте выше), чтобы изменить его текст и варианты ответа.</p>
                        <p class="bse-note">Связи: кликните кружок справа от выхода, затем кликните по
                            следующему блоку (или просто потяните от кружка к блоку).
                            Чтобы разорвать связь, потяните её с выхода в пустое место.</p>

                        <div class="bse-fallbacks">
                            <p class="bse-outline-title">Встроенные ответы бота</p>
                            <p class="bse-note">Эти тексты бот отправляет сам, вне блоков сценария.
                                <a class="bse-fallbacks-link" x-bind:href="config.fallbacksUrl">Изменить тексты</a></p>
                            <template x-for="f in config.fallbacks" :key="f.value">
                                <div class="bse-fallback">
                                    <p class="bse-fallback-label" x-text="f.label"></p>
                                    <p class="bse-note" x-text="f.description"></p>
                                    <p class="bse-fallback-text" x-text="'«' + f.text + '»'"></p>
                                </div>
                            </template>
                            <p class="bse-note">Если у шага не подключён выход «Любая другая фраза», отдельного
                                текста нет — бот просто повторяет сообщение текущего шага.</p>
                        </div>

                        <template x-if="config.runBased">
                            <p class="bse-note">Это сценарий на событие: каждый запуск идёт отдельно и не трогает
                                основной диалог контакта. Кнопки его сообщений несут уникальный токен запуска, поэтому
                                ответ спустя дни попадает точно в свою ветку и опубликованную на тот момент версию.</p>
                        </template>
                    </div>
                </template>
            </aside>
        </div>

        <div class="bse-actions">
            @unless ($scenario->isPublished())
                <button type="button" class="bse-btn bse-btn-lg bse-btn-danger"
                        wire:click="deleteScenario"
                        wire:confirm="Удалить сценарий «{{ $scenario->name }}»?">
                    Удалить сценарий
                </button>
            @endunless
            <span class="bse-toolbar-spring"></span>
            <button type="button" class="bse-btn bse-btn-lg" x-on:click="save()" x-bind:disabled="busy">
                Сохранить черновик
            </button>
            <button type="button" class="bse-btn bse-btn-lg bse-btn-primary" x-on:click="publish()" x-bind:disabled="busy">
                Опубликовать сценарий
            </button>
        </div>

        <script>
            document.addEventListener('alpine:init', () => {
                Alpine.data('botScenarioEditor', (initial, config) => ({
                    nodes: (initial.nodes ?? []).map((n) => ({ text: '', options: [], ...n })),
                    edges: initial.edges ?? [],
                    config: config ?? { runBased: false, templates: [], variables: [], conditions: [], actions: [], fallbacks: [], fallbacksUrl: '' },
                    pan: { x: 40, y: 20 },
                    scale: 1,
                    selectedId: null,
                    search: '',
                    busy: false,
                    drag: null,
                    linking: null,
                    linkLine: null,
                    layoutBackup: null,

                    NODE_W: 260,
                    HEADER_H: 32,
                    TEXT_H: 40,
                    OUT_H: 28,

                    typeLabels: {
                        start: 'Старт', text: 'Текст', buttons: 'Меню (кнопки)', list: 'Список',
                        ai: 'Запрос ввода (AI)', my_listings: 'Мои объявления (CTA)',
                        message: 'WhatsApp-сообщение', condition: 'Условие', action: 'Действие', end: 'Завершение',
                    },
                    optionLimit: { buttons: 3, list: 10, message: 3 },

                    init() {
                        window.addEventListener('pointermove', (e) => this.onMove(e))
                        window.addEventListener('pointerup', (e) => this.onUp(e))
                        window.addEventListener('keydown', (e) => {
                            if (e.key === 'Escape' && this.linking) this.cancelLink()
                        })
                        this.$nextTick(() => this.fitView())
                    },

                    get selected() {
                        return this.nodes.find((n) => n.id === this.selectedId) ?? null
                    },

                    // Не «node»: имя затенялось бы переменной x-for="node in nodes"
                    // в scope обработчиков внутри цикла.
                    findNode(id) {
                        return this.nodes.find((n) => n.id === id)
                    },

                    outputsOf(node) {
                        if (node.type === 'buttons' || node.type === 'list') {
                            return [
                                ...(node.options ?? []).map((o) => ({ key: 'option:' + o.id, label: o.title || 'Без названия' })),
                                { key: 'fallback', label: 'Любая другая фраза' },
                            ]
                        }

                        if (node.type === 'message') {
                            const outs = (node.options ?? []).map((o) => ({ key: 'option:' + o.id, label: o.title || 'Без названия' }))

                            if ((node.timeout_hours ?? 0) > 0) {
                                outs.push({ key: 'timeout', label: 'Таймаут' })
                            }

                            return outs.length ? outs : [{ key: 'continue', label: 'Продолжить' }]
                        }

                        if (node.type === 'condition') {
                            return [{ key: 'yes', label: 'Да' }, { key: 'no', label: 'Нет' }]
                        }

                        if (node.type === 'action') {
                            const action = this.actionConfig(node)

                            return action?.skippable
                                ? [
                                    { key: 'continue', label: 'Выполнено' },
                                    { key: 'skipped', label: action.skipped_label },
                                ]
                                : [{ key: 'continue', label: 'Продолжить' }]
                        }

                        if (node.type === 'end') {
                            return []
                        }

                        if (node.type === 'start' && ! this.config.runBased) {
                            // Главный диалог: необязательный второй выход для
                            // тех, кто уже общался с ботом раньше.
                            return [
                                { key: 'continue', label: 'Первое обращение' },
                                { key: 'returning', label: 'Повторное обращение' },
                            ]
                        }

                        return [{ key: 'continue', label: 'Продолжить' }]
                    },

                    listLabel(list, value) {
                        return (list ?? []).find((i) => i.value === value)?.label ?? ''
                    },

                    actionConfig(node) {
                        return this.config.actions.find((a) => a.value === node?.action)
                    },

                    // Смена действия убирает устаревшую связь «не выполнено»:
                    // у нового действия такого исхода может не быть.
                    onActionChanged(node) {
                        if (! this.actionConfig(node)?.skippable) {
                            this.setTarget(node.id, 'skipped', '')
                        }
                    },

                    // Зеркалит ScenarioValidator::templatePlaceholderCount.
                    templatePlaceholderCount(body) {
                        const indexes = [...(body ?? '').matchAll(/\{\{(\d+)\}\}/g)].map((m) => parseInt(m[1], 10))

                        return indexes.length ? Math.max(...indexes) : 0
                    },

                    /**
                     * Держит строки «Переменные шаблона» в точном числе
                     * плейсхолдеров выбранного шаблона: оператор только
                     * выбирает значения. Шаблон, которого нет в реестре,
                     * не трогаем — сохранённое сопоставление не теряется.
                     */
                    syncTemplateVariables(node) {
                        if (! node || node.type !== 'message' || node.channel === 'session') {
                            return
                        }

                        const template = this.config.templates.find((t) => t.name === node.template_name)

                        if (! template) {
                            return
                        }

                        const count = this.templatePlaceholderCount(template.body)
                        const current = Array.isArray(node.variables) ? node.variables : []

                        if (current.length === count) {
                            return
                        }

                        node.variables = Array.from({ length: count }, (_, i) => current[i] ?? '')
                    },

                    nodeSummary(node) {
                        if (node.type === 'condition') {
                            return this.listLabel(this.config.conditions, node.condition) || '(условие не выбрано)'
                        }

                        if (node.type === 'action') {
                            return this.listLabel(this.config.actions, node.action) || '(действие не выбрано)'
                        }

                        if (node.type === 'end') {
                            return 'Конец ветки'
                        }

                        // Текст шаблонного сообщения задаёт выбранный шаблон Meta.
                        if (node.type === 'message' && node.channel !== 'session') {
                            const template = this.config.templates.find(t => t.name === node.template_name)

                            return template?.body
                                || (node.template_name ? 'Шаблон: ' + node.template_name : '(шаблон не выбран)')
                        }

                        return node.text || '(текст не заполнен)'
                    },

                    nodeHeight(node) {
                        return this.HEADER_H + (node.type === 'start' ? 0 : this.TEXT_H) + this.outputsOf(node).length * this.OUT_H
                    },

                    portPos(nodeId, key) {
                        const n = this.findNode(nodeId)
                        if (! n) return null
                        const idx = this.outputsOf(n).findIndex((o) => o.key === key)
                        if (idx < 0) return null

                        return {
                            x: n.x + this.NODE_W,
                            y: n.y + this.HEADER_H + (n.type === 'start' ? 0 : this.TEXT_H) + idx * this.OUT_H + this.OUT_H / 2,
                        }
                    },

                    inputPos(nodeId) {
                        const n = this.findNode(nodeId)

                        return n ? { x: n.x, y: n.y + this.HEADER_H / 2 } : null
                    },

                    // Веер входа: стрелки в один блок расходятся по вертикали,
                    // чтобы не сливаться в одну точку.
                    edgeInPoint(edge) {
                        const b = this.inputPos(edge.to)
                        if (! b) return null
                        const incoming = this.edges.filter((e) => e.to === edge.to)
                        if (incoming.length < 2) return b
                        const idx = incoming.findIndex((e) => e.from === edge.from && e.output === edge.output)

                        return { x: b.x, y: b.y + (idx - (incoming.length - 1) / 2) * 10 }
                    },

                    edgePath(edge) {
                        const a = this.portPos(edge.from, edge.output)
                        const b = this.edgeInPoint(edge)
                        if (! a || ! b) return ''
                        const dx = Math.max(50, Math.abs(b.x - a.x) / 2)

                        return `M ${a.x} ${a.y} C ${a.x + dx} ${a.y}, ${b.x - dx} ${b.y}, ${b.x} ${b.y}`
                    },

                    arrowPath(edge) {
                        const b = this.edgeInPoint(edge)

                        return b ? `M ${b.x - 10} ${b.y - 5.5} L ${b.x - 1} ${b.y} L ${b.x - 10} ${b.y + 5.5} Z` : ''
                    },

                    // Середина кубической Безье из edgePath: контрольные точки
                    // симметричны, поэтому B(0.5) — середина отрезка концов.
                    edgeMid(edge) {
                        const a = this.portPos(edge.from, edge.output)
                        const b = this.edgeInPoint(edge)
                        if (! a || ! b) return null

                        return { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 }
                    },

                    edgeLabel(edge) {
                        const n = this.findNode(edge.from)
                        const label = n ? (this.outputsOf(n).find((o) => o.key === edge.output)?.label ?? '') : ''
                        if (label === 'Продолжить') return '' // единственный «шумовой» лейбл

                        return label.length > 24 ? label.slice(0, 23) + '…' : label
                    },

                    edgeGroup(output) {
                        if (output.startsWith('option:')) return 'option'

                        return ['yes', 'no', 'timeout', 'fallback', 'returning', 'skipped'].includes(output) ? output : 'continue'
                    },

                    edgeClasses(edge) {
                        return {
                            ['bse-edge--' + this.edgeGroup(edge.output)]: true,
                            'bse-edge-active': this.selectedId !== null && (edge.from === this.selectedId || edge.to === this.selectedId),
                            'bse-edge-dim': this.selectedId !== null && edge.from !== this.selectedId && edge.to !== this.selectedId,
                        }
                    },

                    isConnected(nodeId, key) {
                        return this.edges.some((e) => e.from === nodeId && e.output === key)
                    },

                    targetOf(nodeId, key) {
                        return this.edges.find((e) => e.from === nodeId && e.output === key)?.to ?? null
                    },

                    setTarget(nodeId, key, targetId) {
                        this.edges = this.edges.filter((x) => ! (x.from === nodeId && x.output === key))

                        if (targetId) {
                            this.edges.push({ from: nodeId, output: key, to: targetId })
                        }
                    },

                    /**
                     * Номера шагов: BFS от «Старта» в порядке выходов —
                     * порядок чтения схемы. «•» — блок не связан со «Стартом».
                     */
                    stepNumbers() {
                        const numbers = new Map()
                        const start = this.nodes.find((n) => n.type === 'start')
                        if (! start) return numbers
                        const queue = [start.id]
                        numbers.set(start.id, 1)

                        while (queue.length) {
                            const id = queue.shift()
                            const n = this.findNode(id)
                            if (! n) continue
                            this.outputsOf(n).forEach((out) => {
                                const to = this.targetOf(id, out.key)
                                if (to && ! numbers.has(to) && this.findNode(to)) {
                                    numbers.set(to, numbers.size + 1)
                                    queue.push(to)
                                }
                            })
                        }

                        return numbers
                    },

                    stepNumber(id) {
                        return this.stepNumbers().get(id) ?? '•'
                    },

                    nodeLabel(node) {
                        const base = this.nodeBaseLabel(node)
                        const step = this.stepNumbers().get(node.id)

                        if (step) {
                            return `Шаг ${step} · ${base}`
                        }

                        const twins = this.nodes.filter(n => this.nodeBaseLabel(n) === base)

                        if (twins.length < 2) {
                            return base
                        }

                        return `${base} № ${twins.findIndex(n => n.id === node.id) + 1}`
                    },

                    nodeBaseLabel(node) {
                        const type = this.typeLabels[node.type] ?? node.type

                        if (node.type === 'condition' || node.type === 'action') {
                            const label = node.type === 'condition'
                                ? this.listLabel(this.config.conditions, node.condition)
                                : this.listLabel(this.config.actions, node.action)

                            return label ? type + ': ' + label : type
                        }

                        const text = node.type === 'message' && node.channel !== 'session'
                            ? this.nodeSummary(node)
                            : (node.text ?? '').trim()

                        return text && !text.startsWith('(') ? type + ': ' + text.slice(0, 30) : type
                    },

                    toCanvas(e) {
                        const r = this.$refs.viewport.getBoundingClientRect()

                        return { x: (e.clientX - r.left - this.pan.x) / this.scale, y: (e.clientY - r.top - this.pan.y) / this.scale }
                    },

                    onWheel(e) {
                        const factor = e.deltaY < 0 ? 1.1 : 0.9
                        this.zoomAt(e, Math.min(1.75, Math.max(0.3, this.scale * factor)))
                    },

                    zoomAt(e, newScale) {
                        const r = this.$refs.viewport.getBoundingClientRect()
                        const cx = e ? e.clientX - r.left : r.width / 2
                        const cy = e ? e.clientY - r.top : r.height / 2
                        this.pan.x = cx - ((cx - this.pan.x) / this.scale) * newScale
                        this.pan.y = cy - ((cy - this.pan.y) / this.scale) * newScale
                        this.scale = newScale
                    },

                    zoomIn() { this.zoomAt(null, Math.min(1.75, this.scale * 1.2)) },
                    zoomOut() { this.zoomAt(null, Math.max(0.3, this.scale / 1.2)) },

                    fitView() {
                        if (! this.nodes.length) return
                        const r = this.$refs.viewport.getBoundingClientRect()
                        const minX = Math.min(...this.nodes.map((n) => n.x)) - 40
                        const minY = Math.min(...this.nodes.map((n) => n.y)) - 40
                        const maxX = Math.max(...this.nodes.map((n) => n.x + this.NODE_W)) + 40
                        const maxY = Math.max(...this.nodes.map((n) => n.y + this.nodeHeight(n))) + 40
                        this.scale = Math.min(1, r.width / (maxX - minX), r.height / (maxY - minY))
                        this.pan.x = (r.width - (maxX - minX) * this.scale) / 2 - minX * this.scale
                        this.pan.y = (r.height - (maxY - minY) * this.scale) / 2 - minY * this.scale
                    },

                    startPan(e) {
                        if (this.linking) {
                            this.cancelLink()

                            return
                        }

                        this.selectedId = null
                        this.drag = { kind: 'pan', sx: e.clientX, sy: e.clientY, px: this.pan.x, py: this.pan.y }
                    },

                    startNodeDrag(node, e) {
                        if (this.linking) {
                            this.finishLink(node.id)

                            return
                        }

                        this.layoutBackup = null // ручной drag фиксирует новую расстановку
                        this.selectedId = node.id
                        this.drag = { kind: 'node', id: node.id, sx: e.clientX, sy: e.clientY, nx: node.x, ny: node.y }
                    },

                    /**
                     * «Выровнять схему»: слой блока — длиннейший путь от
                     * «Старта» (back-рёбра циклов отсекаются DFS-ом),
                     * недостижимые блоки уходят в последний слой; внутри
                     * слоя порядок — по среднему Y предшественников.
                     */
                    autoLayout() {
                        if (! this.nodes.length) return
                        this.layoutBackup = this.nodes.map((n) => ({ id: n.id, x: n.x, y: n.y }))

                        const start = this.nodes.find((n) => n.type === 'start')
                        const layers = new Map()
                        const onPath = new Set()
                        const forward = []

                        const visit = (id, layer) => {
                            if (onPath.has(id)) return // back-ребро цикла
                            if ((layers.get(id) ?? -1) >= layer) return
                            layers.set(id, layer)
                            onPath.add(id)
                            this.edges.filter((e) => e.from === id && this.findNode(e.to)).forEach((e) => visit(e.to, layer + 1))
                            onPath.delete(id)
                        }

                        if (start) visit(start.id, 0)

                        const maxLayer = Math.max(0, ...layers.values())
                        this.nodes.forEach((n) => {
                            if (! layers.has(n.id)) layers.set(n.id, maxLayer + 1)
                        })

                        const byLayer = new Map()
                        this.nodes.forEach((n) => {
                            const l = layers.get(n.id)
                            byLayer.set(l, [...(byLayer.get(l) ?? []), n])
                        })

                        const prevY = (n) => {
                            const sources = this.edges.filter((e) => e.to === n.id).map((e) => this.findNode(e.from)).filter(Boolean)

                            return sources.length ? sources.reduce((s, p) => s + p.y, 0) / sources.length : n.y
                        }

                        ;[...byLayer.keys()].sort((a, b) => a - b).forEach((layer) => {
                            const row = byLayer.get(layer).sort((a, b) => prevY(a) - prevY(b))
                            let y = 40
                            row.forEach((n) => {
                                n.x = 40 + layer * (this.NODE_W + 140)
                                n.y = y
                                y += this.nodeHeight(n) + 40
                            })
                        })

                        this.fitView()
                    },

                    restoreLayout() {
                        (this.layoutBackup ?? []).forEach(({ id, x, y }) => {
                            const n = this.findNode(id)
                            if (n) { n.x = x; n.y = y }
                        })
                        this.layoutBackup = null
                        this.fitView()
                    },

                    startLink(node, key, e) {
                        const from = this.portPos(node.id, key)
                        const p = this.toCanvas(e)
                        this.linking = { from: node.id, output: key }
                        this.drag = { kind: 'link', sx: e.clientX, sy: e.clientY }
                        this.linkLine = { x1: from.x, y1: from.y, x2: p.x, y2: p.y }
                    },

                    /**
                     * Заменяет прежнюю связь этого выхода; targetId === null — разрыв.
                     */
                    finishLink(targetId) {
                        const { from, output } = this.linking
                        this.edges = this.edges.filter((x) => ! (x.from === from && x.output === output))

                        if (targetId) {
                            this.edges.push({ from, output, to: targetId })
                        }

                        this.cancelLink()
                    },

                    cancelLink() {
                        this.linking = null
                        this.linkLine = null
                    },

                    onMove(e) {
                        if (this.linking && this.linkLine) {
                            const p = this.toCanvas(e)
                            this.linkLine.x2 = p.x
                            this.linkLine.y2 = p.y
                        }

                        if (! this.drag) return

                        if (this.drag.kind === 'pan') {
                            this.pan.x = this.drag.px + e.clientX - this.drag.sx
                            this.pan.y = this.drag.py + e.clientY - this.drag.sy
                        } else if (this.drag.kind === 'node') {
                            const n = this.findNode(this.drag.id)
                            n.x = this.drag.nx + (e.clientX - this.drag.sx) / this.scale
                            n.y = this.drag.ny + (e.clientY - this.drag.sy) / this.scale
                        }
                    },

                    onUp(e) {
                        if (this.drag?.kind === 'link' && this.linking) {
                            const moved = Math.hypot(e.clientX - this.drag.sx, e.clientY - this.drag.sy)

                            if (moved < 8) {
                                // Клик по кружку: соединение завершится кликом по блоку-цели.
                                this.drag = null

                                return
                            }

                            const target = document.elementFromPoint(e.clientX, e.clientY)?.closest('[data-node-id]')?.dataset.nodeId
                            this.finishLink(target ?? null)
                        }

                        this.drag = null
                    },

                    addNode(type) {
                        const r = this.$refs.viewport.getBoundingClientRect()
                        const center = { x: (r.width / 2 - this.pan.x) / this.scale, y: (r.height / 2 - this.pan.y) / this.scale }
                        const node = { id: type + '_' + Date.now().toString(36), type, x: center.x - this.NODE_W / 2, y: center.y - 60, text: '', options: [] }

                        if (type === 'buttons' || type === 'list') {
                            node.options = [{ id: this.newOptionId(), title: '' }]
                        }

                        if (type === 'list') {
                            node.button = 'Выбрать'
                        }

                        if (type === 'ai') {
                            node.task = 'collect_listing'
                            node.listing_type = ''
                        }

                        if (type === 'my_listings') {
                            node.text = 'Откройте кабинет — там ваши объявления, статусы и причины отклонения.'
                        }

                        if (type === 'message') {
                            node.channel = 'adaptive'
                            node.template_name = ''
                            node.variables = []
                            node.timeout_hours = 0
                            node.options = [{ id: this.newOptionId(), title: '' }]
                        }

                        if (type === 'condition') {
                            node.condition = this.config.conditions[0]?.value ?? ''
                        }

                        if (type === 'action') {
                            node.action = this.config.actions[0]?.value ?? ''
                        }

                        this.nodes.push(node)
                        this.selectedId = node.id
                    },

                    removeNode(id) {
                        const n = this.findNode(id)
                        if (! n || n.type === 'start') return
                        this.nodes = this.nodes.filter((x) => x.id !== id)
                        this.edges = this.edges.filter((x) => x.from !== id && x.to !== id)

                        if (this.selectedId === id) {
                            this.selectedId = null
                        }
                    },

                    newOptionId() {
                        return 'o' + Date.now().toString(36) + Math.floor(Math.random() * 1296).toString(36)
                    },

                    addOption(node) {
                        if ((node.options ?? []).length >= this.optionLimit[node.type]) return
                        node.options.push({ id: this.newOptionId(), title: '' })
                    },

                    removeOption(node, optionId) {
                        node.options = node.options.filter((o) => o.id !== optionId)
                        this.edges = this.edges.filter((x) => ! (x.from === node.id && x.output === 'option:' + optionId))
                    },

                    matches(node) {
                        const q = this.search.trim().toLowerCase()
                        if (! q) return false

                        return (node.text ?? '').toLowerCase().includes(q)
                            || (node.options ?? []).some((o) => (o.title ?? '').toLowerCase().includes(q))
                            || (this.typeLabels[node.type] ?? '').toLowerCase().includes(q)
                    },

                    focusSearch() {
                        const hit = this.nodes.find((n) => this.matches(n))
                        if (hit) this.focusNode(hit.id)
                    },

                    focusNode(id) {
                        const n = this.findNode(id)
                        if (! n) return
                        const r = this.$refs.viewport.getBoundingClientRect()
                        this.pan.x = r.width / 2 - (n.x + this.NODE_W / 2) * this.scale
                        this.pan.y = r.height / 2 - (n.y + 80) * this.scale
                        this.selectedId = n.id
                    },

                    /**
                     * Текстовое оглавление веток: обход в глубину от «Старта»
                     * в порядке выходов; via — подпись выхода, которым пришли.
                     * Блок показывается на месте первого захода, повторные
                     * входы в него оглавление не дублируют.
                     */
                    outline() {
                        const rows = []
                        const visited = new Set()
                        const start = this.nodes.find((n) => n.type === 'start')

                        const walk = (id, depth, via) => {
                            const n = this.findNode(id)
                            if (! n || visited.has(id)) return
                            visited.add(id)
                            rows.push({ id, depth, via, label: this.nodeLabel(n) })
                            this.outputsOf(n).forEach((out) => {
                                const to = this.targetOf(id, out.key)
                                if (to) walk(to, depth + 1, out.label)
                            })
                        }

                        if (start) walk(start.id, 0, null)

                        this.nodes.forEach((n) => {
                            if (! visited.has(n.id)) {
                                rows.push({ id: n.id, depth: 0, via: null, label: this.nodeLabel(n), unreachable: true })
                            }
                        })

                        return rows
                    },

                    checkResult: null,

                    async runCheck() {
                        this.busy = true

                        try {
                            this.checkResult = await this.$wire.check(this.serialize())
                        } finally {
                            this.busy = false
                        }
                    },

                    serialize() {
                        return JSON.parse(JSON.stringify({ nodes: this.nodes, edges: this.edges }))
                    },

                    async save() {
                        this.busy = true

                        try {
                            await this.$wire.saveDraft(this.serialize())
                        } finally {
                            this.busy = false
                        }
                    },

                    async publish() {
                        this.busy = true

                        try {
                            await this.$wire.publish(this.serialize())
                        } finally {
                            this.busy = false
                        }
                    },
                }))
            })
        </script>

        <style>
            .bse { display: flex; flex-direction: column; gap: 0.75rem; }
            .bse-dragging { user-select: none; }

            .bse-tabs { display: flex; flex-wrap: wrap; align-items: flex-start; gap: 0.5rem; }
            .bse-tab {
                display: inline-flex; flex-direction: column; align-items: flex-start; gap: 0.125rem;
                padding: 0.375rem 0.75rem; border-radius: 0.5rem; border: 1px solid rgb(209 213 219);
                background: white; cursor: pointer; text-align: left;
            }
            .bse-tab:hover { background: rgb(249 250 251); }
            .bse-tab-active { border-color: rgb(217 119 6); box-shadow: 0 0 0 2px rgb(217 119 6 / 0.35); }
            .bse-tab-name { font-size: 0.875rem; font-weight: 600; color: rgb(55 65 81); }
            .bse-tab-trigger { font-size: 0.6875rem; color: rgb(107 114 128); }
            .bse-create summary { list-style: none; cursor: pointer; }
            .bse-create summary::-webkit-details-marker { display: none; }
            .bse-create-form {
                margin-top: 0.5rem; padding: 0.75rem; border: 1px solid rgb(229 231 235); border-radius: 0.5rem;
                background: white; display: flex; flex-direction: column; gap: 0.5rem; width: 20rem;
            }

            .bse-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem; }
            .bse-hintbar { font-size: 0.8125rem; color: rgb(107 114 128); }
            .bse-hint-active { color: rgb(217 119 6); font-weight: 600; }
            .bse-toolbar-label { font-size: 0.875rem; color: rgb(107 114 128); }
            .bse-toolbar-spring { flex: 1; }
            .bse-toolbar-sep { width: 1px; height: 1.5rem; background: rgb(229 231 235); }
            .bse-zoom { font-size: 0.875rem; color: rgb(107 114 128); min-width: 3rem; text-align: center; }

            .bse-btn {
                padding: 0.375rem 0.75rem; font-size: 0.875rem; font-weight: 500; border-radius: 0.5rem;
                background: white; color: rgb(55 65 81); border: 1px solid rgb(209 213 219); cursor: pointer;
            }
            .bse-btn:hover { background: rgb(249 250 251); }
            .bse-btn:disabled { opacity: 0.5; cursor: not-allowed; }
            .bse-btn-primary { background: rgb(217 119 6); border-color: rgb(217 119 6); color: white; }
            .bse-btn-primary:hover { background: rgb(180 83 9); }
            .bse-btn-danger { color: rgb(185 28 28); border-color: rgb(252 165 165); }
            .bse-btn-lg { padding: 0.5rem 1.25rem; }
            .bse-btn-block { width: 100%; }

            .bse-body { display: flex; gap: 0.75rem; align-items: stretch; }

            .bse-viewport {
                position: relative; flex: 1; overflow: hidden; min-height: 560px; height: calc(100vh - 22rem);
                border: 1px solid rgb(229 231 235); border-radius: 0.75rem; background-color: rgb(249 250 251);
                background-image: radial-gradient(circle, rgb(209 213 219) 1px, transparent 1px);
                touch-action: none; cursor: grab;
            }
            .bse-dragging .bse-viewport { cursor: grabbing; }

            .bse-world { position: absolute; left: 0; top: 0; transform-origin: 0 0; }
            .bse-edges { position: absolute; left: 0; top: 0; width: 1px; height: 1px; overflow: visible; pointer-events: none; }
            .bse-edge { fill: none; stroke: rgb(156 163 175); stroke-width: 2; }
            .bse-edge-temp { stroke: rgb(217 119 6); stroke-dasharray: 6 4; }

            /* Палитра веток: линия, стрелка, подпись и порт-источник — одним
               цветом; оранжевый занят механикой выделения и связывания. */
            .bse-edge-svg { transition: opacity 0.15s; }
            .bse-edge-svg .bse-arrow { fill: rgb(156 163 175); stroke: none; }
            .bse-edge-label {
                font-size: 11px; font-weight: 600; fill: rgb(107 114 128);
                paint-order: stroke; stroke: rgb(249 250 251); stroke-width: 4px; stroke-linejoin: round;
            }
            .bse-edge--yes .bse-edge { stroke: rgb(22 163 74); }
            .bse-edge--yes .bse-arrow, .bse-edge--yes .bse-edge-label { fill: rgb(22 163 74); }
            .bse-edge--no .bse-edge, .bse-edge--skipped .bse-edge { stroke: rgb(239 68 68); }
            .bse-edge--no .bse-arrow, .bse-edge--no .bse-edge-label,
            .bse-edge--skipped .bse-arrow, .bse-edge--skipped .bse-edge-label { fill: rgb(239 68 68); }
            .bse-edge--timeout .bse-edge { stroke: rgb(139 92 246); }
            .bse-edge--timeout .bse-arrow, .bse-edge--timeout .bse-edge-label { fill: rgb(139 92 246); }
            .bse-edge--option .bse-edge { stroke: rgb(59 130 246); }
            .bse-edge--option .bse-arrow, .bse-edge--option .bse-edge-label { fill: rgb(59 130 246); }
            .bse-edge--fallback .bse-edge { stroke: rgb(107 114 128); stroke-dasharray: 5 4; }
            .bse-edge--fallback .bse-arrow, .bse-edge--fallback .bse-edge-label { fill: rgb(107 114 128); }
            .bse-edge--returning .bse-edge { stroke: rgb(8 145 178); }
            .bse-edge--returning .bse-arrow, .bse-edge--returning .bse-edge-label { fill: rgb(8 145 178); }

            .bse-edge-active .bse-edge { stroke-width: 3.5; }
            .bse-edge-active .bse-edge-label { font-weight: 700; }
            .bse-edge-dim { opacity: 0.18; }

            .bse-legend {
                position: absolute; left: 0.75rem; bottom: 0.75rem; display: flex; gap: 0.75rem; flex-wrap: wrap;
                padding: 0.375rem 0.625rem; border-radius: 0.5rem; font-size: 0.6875rem; color: rgb(107 114 128);
                background: rgb(255 255 255 / 0.85); border: 1px solid rgb(229 231 235); pointer-events: none;
            }
            .bse-legend-item { display: inline-flex; align-items: center; }
            .bse-legend-line { display: inline-block; width: 16px; height: 0; border-top: 2px solid; margin-right: 4px; }
            .bse-legend-line--yes { border-top-color: rgb(22 163 74); }
            .bse-legend-line--no { border-top-color: rgb(239 68 68); }
            .bse-legend-line--timeout { border-top-color: rgb(139 92 246); }
            .bse-legend-line--option { border-top-color: rgb(59 130 246); }
            .bse-legend-line--fallback { border-top-color: rgb(107 114 128); border-top-style: dashed; }

            .bse-node {
                position: absolute; width: 260px; border-radius: 0.5rem; background: white;
                border: 1px solid rgb(209 213 219); box-shadow: 0 1px 3px rgb(0 0 0 / 0.1); cursor: move;
                touch-action: none;
            }
            .bse-transition { display: flex; flex-direction: column; gap: 0.125rem; margin-bottom: 0.375rem; }
            .bse-node-start .bse-node-header { color: rgb(21 128 61); }
            .bse-selected { border-color: rgb(217 119 6); box-shadow: 0 0 0 2px rgb(217 119 6 / 0.35); }
            .bse-hit { box-shadow: 0 0 0 3px rgb(59 130 246 / 0.5); }

            .bse-node-header {
                height: 32px; display: flex; align-items: center; gap: 0.5rem; padding: 0 0.75rem;
                font-size: 0.8125rem; font-weight: 600; border-bottom: 1px solid rgb(243 244 246); position: relative;
            }
            .bse-node-in {
                position: absolute; left: -7px; top: 9px; width: 14px; height: 14px; border-radius: 9999px;
                background: rgb(229 231 235); border: 2px solid rgb(156 163 175);
            }
            .bse-node-type { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

            /* Типизация блоков: цветная полоска слева, иконка и заголовок
               в цвете типа — категория блока видна до чтения текста. */
            .bse-node-icon { width: 16px; height: 16px; flex-shrink: 0; }
            .bse-btn-icon { width: 14px; height: 14px; display: inline-block; vertical-align: -2px; }
            .bse-step {
                min-width: 18px; height: 18px; border-radius: 9999px; background: rgb(243 244 246);
                color: rgb(75 85 99); font-size: 0.6875rem; font-weight: 600; padding: 0 4px;
                display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0;
            }
            .bse-type-start { border-left: 4px solid rgb(21 128 61); }
            .bse-type-start .bse-node-header, .bse-icon--start { color: rgb(21 128 61); }
            .bse-type-text { border-left: 4px solid rgb(2 132 199); }
            .bse-type-text .bse-node-header, .bse-icon--text { color: rgb(2 132 199); }
            .bse-type-buttons, .bse-type-list { border-left: 4px solid rgb(14 116 144); }
            .bse-type-buttons .bse-node-header, .bse-type-list .bse-node-header,
            .bse-icon--buttons, .bse-icon--list { color: rgb(14 116 144); }
            .bse-type-message { border-left: 4px solid rgb(13 148 136); }
            .bse-type-message .bse-node-header, .bse-icon--message { color: rgb(13 148 136); }
            .bse-type-ai { border-left: 4px solid rgb(124 58 237); }
            .bse-type-ai .bse-node-header, .bse-icon--ai { color: rgb(124 58 237); }
            .bse-type-my_listings { border-left: 4px solid rgb(79 70 229); }
            .bse-type-my_listings .bse-node-header, .bse-icon--my_listings { color: rgb(79 70 229); }
            .bse-type-condition { border-left: 4px solid rgb(180 83 9); }
            .bse-type-condition .bse-node-header, .bse-icon--condition { color: rgb(180 83 9); }
            .bse-type-action { border-left: 4px solid rgb(234 88 12); }
            .bse-type-action .bse-node-header, .bse-icon--action { color: rgb(234 88 12); }
            .bse-type-end { border-left: 4px solid rgb(107 114 128); }
            .bse-type-end .bse-node-header, .bse-icon--end { color: rgb(107 114 128); }
            .bse-node-text {
                height: 40px; padding: 0.25rem 0.75rem; font-size: 0.75rem; color: rgb(107 114 128);
                overflow: hidden; border-bottom: 1px solid rgb(243 244 246);
            }
            .bse-out {
                height: 28px; display: flex; align-items: center; justify-content: space-between;
                padding: 0 0.75rem; font-size: 0.75rem; color: rgb(55 65 81); position: relative;
            }
            .bse-out-label { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
            .bse-port {
                position: absolute; right: -9px; top: 5px; width: 18px; height: 18px; border-radius: 9999px;
                background: white; border: 3px solid rgb(217 119 6); cursor: crosshair; transition: transform 0.1s;
            }
            .bse-port:hover { transform: scale(1.3); box-shadow: 0 0 0 4px rgb(217 119 6 / 0.25); }
            .bse-port-connected { background: rgb(217 119 6); }
            .bse-port--yes { border-color: rgb(22 163 74); }
            .bse-port--yes.bse-port-connected { background: rgb(22 163 74); }
            .bse-port--no, .bse-port--skipped { border-color: rgb(239 68 68); }
            .bse-port--no.bse-port-connected, .bse-port--skipped.bse-port-connected { background: rgb(239 68 68); }
            .bse-port--timeout { border-color: rgb(139 92 246); }
            .bse-port--timeout.bse-port-connected { background: rgb(139 92 246); }
            .bse-port--option { border-color: rgb(59 130 246); }
            .bse-port--option.bse-port-connected { background: rgb(59 130 246); }
            .bse-port--fallback { border-color: rgb(107 114 128); }
            .bse-port--fallback.bse-port-connected { background: rgb(107 114 128); }
            .bse-port--returning { border-color: rgb(8 145 178); }
            .bse-port--returning.bse-port-connected { background: rgb(8 145 178); }
            .bse-link-target { outline: 2px dashed rgb(217 119 6); outline-offset: 3px; cursor: pointer; }

            .bse-panel {
                width: 20rem; flex-shrink: 0; border: 1px solid rgb(229 231 235); border-radius: 0.75rem;
                background: white; overflow-y: auto; max-height: calc(100vh - 22rem); min-height: 560px;
            }
            .bse-panel-body { padding: 1rem; display: flex; flex-direction: column; gap: 0.75rem; }
            .bse-panel-title { font-weight: 600; font-size: 0.9375rem; }

            .bse-check { display: flex; flex-direction: column; gap: 0.375rem; align-items: flex-start; }
            .bse-check-ok { font-size: 0.8125rem; font-weight: 600; color: rgb(22 163 74); }
            .bse-check-error { font-size: 0.75rem; color: rgb(220 38 38); }
            .bse-check-warning { font-size: 0.75rem; color: rgb(180 83 9); }
            .bse-check-link { cursor: pointer; text-decoration: underline dotted; }

            .bse-fallbacks { display: flex; flex-direction: column; gap: 0.375rem; border-top: 1px solid rgb(229 231 235); padding-top: 0.75rem; }
            .bse-fallback { display: flex; flex-direction: column; gap: 0.125rem; }
            .bse-fallback-label { font-size: 0.75rem; font-weight: 600; color: rgb(55 65 81); }
            .bse-fallback-text { font-size: 0.75rem; font-style: italic; color: rgb(107 114 128); }
            .bse-fallbacks-link { color: rgb(217 119 6); text-decoration: underline dotted; }

            .bse-outline { display: flex; flex-direction: column; gap: 0.125rem; }
            .bse-outline-title { font-size: 0.8125rem; font-weight: 600; color: rgb(55 65 81); margin-bottom: 0.25rem; }
            .bse-outline-row {
                display: block; width: 100%; text-align: left; font-size: 0.75rem; color: rgb(55 65 81);
                background: none; border: none; padding: 0.125rem 0.25rem; border-radius: 0.25rem; cursor: pointer;
            }
            .bse-outline-row:hover { background: rgb(243 244 246); }
            .bse-outline-via { color: rgb(107 114 128); }
            .bse-outline-unreachable { color: rgb(180 83 9); }
            .bse-field { display: flex; flex-direction: column; gap: 0.375rem; font-size: 0.8125rem; font-weight: 500; color: rgb(55 65 81); }
            .bse-note { font-size: 0.75rem; color: rgb(107 114 128); }
            .bse-template-preview { margin-top: 0.5rem; padding: 0.5rem; border: 1px dashed rgb(209 213 219); border-radius: 0.375rem; background: rgb(249 250 251); }
            .bse-template-body { font-size: 0.8rem; white-space: pre-wrap; color: rgb(55 65 81); }
            .bse-option-row { display: flex; gap: 0.375rem; align-items: center; }
            .bse-option-row .bse-input { flex: 1; }
            .bse-var-index { font-size: 0.75rem; color: rgb(107 114 128); min-width: 2.25rem; }

            .bse-input {
                padding: 0.375rem 0.625rem; font-size: 0.875rem; border-radius: 0.5rem; font-weight: 400;
                border: 1px solid rgb(209 213 219); background: white; color: rgb(17 24 39); width: 100%;
            }
            .bse-search { width: 14rem; }
            .bse-actions { display: flex; align-items: center; gap: 0.5rem; }

            :where(.dark) .bse-toolbar-sep { background: rgb(55 65 81); }
            :where(.dark) .bse-btn { background: rgb(31 41 55); border-color: rgb(75 85 99); color: rgb(209 213 219); }
            :where(.dark) .bse-btn:hover { background: rgb(55 65 81); }
            :where(.dark) .bse-btn-primary { background: rgb(217 119 6); border-color: rgb(217 119 6); color: white; }
            :where(.dark) .bse-btn-danger { color: rgb(252 165 165); border-color: rgb(153 27 27); }
            :where(.dark) .bse-tab { background: rgb(31 41 55); border-color: rgb(75 85 99); }
            :where(.dark) .bse-tab:hover { background: rgb(55 65 81); }
            :where(.dark) .bse-tab-name { color: rgb(209 213 219); }
            :where(.dark) .bse-create-form { background: rgb(31 41 55); border-color: rgb(55 65 81); }
            :where(.dark) .bse-viewport { background-color: rgb(17 24 39); border-color: rgb(55 65 81); background-image: radial-gradient(circle, rgb(55 65 81) 1px, transparent 1px); }
            :where(.dark) .bse-node { background: rgb(31 41 55); border-color: rgb(75 85 99); }
            :where(.dark) .bse-node-header { border-color: rgb(55 65 81); }
            :where(.dark) .bse-node-text { border-color: rgb(55 65 81); color: rgb(156 163 175); }
            :where(.dark) .bse-out { color: rgb(209 213 219); }
            :where(.dark) .bse-hintbar { color: rgb(156 163 175); }
            :where(.dark) .bse-port { background: rgb(31 41 55); }
            :where(.dark) .bse-port-connected { background: rgb(217 119 6); }
            :where(.dark) .bse-panel { background: rgb(31 41 55); border-color: rgb(55 65 81); }
            :where(.dark) .bse-panel-title { color: rgb(243 244 246); }
            :where(.dark) .bse-field { color: rgb(209 213 219); }
            :where(.dark) .bse-input { background: rgb(17 24 39); border-color: rgb(75 85 99); color: rgb(243 244 246); }
            :where(.dark) .bse-edge-label { stroke: rgb(17 24 39); }
            :where(.dark) .bse-step { background: rgb(55 65 81); color: rgb(209 213 219); }
            :where(.dark) .bse-outline-title { color: rgb(209 213 219); }
            :where(.dark) .bse-fallbacks { border-color: rgb(55 65 81); }
            :where(.dark) .bse-fallback-label { color: rgb(209 213 219); }
            :where(.dark) .bse-outline-row { color: rgb(209 213 219); }
            :where(.dark) .bse-outline-row:hover { background: rgb(55 65 81); }
            :where(.dark) .bse-legend { background: rgb(31 41 55 / 0.85); border-color: rgb(55 65 81); color: rgb(156 163 175); }
        </style>
    </div>
</x-filament-panels::page>
