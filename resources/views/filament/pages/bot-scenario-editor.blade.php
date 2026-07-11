<x-filament-panels::page>
    @php $scenario = $this->scenario; @endphp

    <div class="flex flex-wrap items-center gap-2 text-sm">
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

    <div wire:ignore x-data="botScenarioEditor(@js($scenario->draft_definition))" class="bse" x-bind:class="{ 'bse-dragging': drag }">
        <div class="bse-toolbar">
            <span class="bse-toolbar-label">Добавить блок:</span>
            <button type="button" class="bse-btn" x-on:click="addNode('text')">+ Текст</button>
            <button type="button" class="bse-btn" x-on:click="addNode('buttons')">+ Меню (кнопки)</button>
            <button type="button" class="bse-btn" x-on:click="addNode('list')">+ Список</button>
            <button type="button" class="bse-btn" x-on:click="addNode('ai')">+ Запрос ввода (AI)</button>

            <span class="bse-toolbar-spring"></span>

            <input type="search" class="bse-input bse-search" placeholder="Поиск по блокам…"
                   x-model="search" x-on:keydown.enter.prevent="focusSearch()">
            <button type="button" class="bse-btn" x-on:click="focusSearch()" title="Показать найденный блок">Найти</button>

            <span class="bse-toolbar-sep"></span>

            <button type="button" class="bse-btn" x-on:click="zoomOut()" title="Уменьшить">−</button>
            <span class="bse-zoom" x-text="Math.round(scale * 100) + '%'"></span>
            <button type="button" class="bse-btn" x-on:click="zoomIn()" title="Увеличить">+</button>
            <button type="button" class="bse-btn" x-on:click="fitView()" title="Показать всю схему">⌖ Центрировать</button>
        </div>

        <div class="bse-hintbar">
            <template x-if="linking">
                <span class="bse-hint-active">Теперь кликните по блоку, к которому ведёт этот выход. Esc — отмена.</span>
            </template>
            <template x-if="!linking">
                <span>Как соединить блоки: кликните (или потяните) оранжевый кружок справа от выхода, затем кликните по следующему блоку. Протяните связь в пустое место, чтобы разорвать её.</span>
            </template>
        </div>

        <div class="bse-body">
            <div class="bse-viewport" x-ref="viewport"
                 x-on:pointerdown.self="startPan($event)"
                 x-on:wheel.prevent="onWheel($event)"
                 x-bind:style="`background-position: ${pan.x}px ${pan.y}px; background-size: ${24 * scale}px ${24 * scale}px`">
                <div class="bse-world" x-bind:style="`transform: translate(${pan.x}px, ${pan.y}px) scale(${scale})`">
                    {{-- Все связи — одним path: <template x-for> внутри <svg> браузеры не поддерживают. --}}
                    <svg class="bse-edges">
                        <path class="bse-edge" x-bind:d="edgesPath()"/>
                        <path class="bse-arrows" x-bind:d="arrowsPath()"/>
                        <path class="bse-edge bse-edge-temp" x-show="linkLine"
                              x-bind:d="linkLine ? `M ${linkLine.x1} ${linkLine.y1} C ${linkLine.x1 + 60} ${linkLine.y1}, ${linkLine.x2 - 60} ${linkLine.y2}, ${linkLine.x2} ${linkLine.y2}` : ''"/>
                    </svg>

                    <template x-for="node in nodes" :key="node.id">
                        <div class="bse-node" x-bind:data-node-id="node.id"
                             x-bind:class="{ 'bse-selected': selectedId === node.id, 'bse-hit': matches(node), 'bse-node-start': node.type === 'start', 'bse-link-target': !!linking }"
                             x-bind:style="`left: ${node.x}px; top: ${node.y}px`"
                             x-on:pointerdown.stop="startNodeDrag(node, $event)">
                            <div class="bse-node-header">
                                <span class="bse-node-in"></span>
                                <span class="bse-node-type" x-text="typeLabels[node.type] ?? node.type"></span>
                            </div>
                            <div class="bse-node-text" x-show="node.type !== 'start'"
                                 x-text="node.text || '(текст не заполнен)'"></div>
                            <template x-for="out in outputsOf(node)" :key="out.key">
                                <div class="bse-out">
                                    <span class="bse-out-label" x-text="out.label"></span>
                                    <span class="bse-port"
                                          x-bind:class="{ 'bse-port-connected': isConnected(node.id, out.key) }"
                                          x-on:pointerdown.stop.prevent="startLink(node, out.key, $event)"
                                          title="Кликните или потяните, чтобы соединить со следующим блоком"></span>
                                </div>
                            </template>
                        </div>
                    </template>
                </div>
            </div>

            <aside class="bse-panel">
                <template x-if="selected">
                    <div class="bse-panel-body">
                        <div class="bse-panel-title" x-text="typeLabels[selected.type] ?? selected.type"></div>

                        <template x-if="selected.type !== 'start' && selected.type !== 'ai'">
                            <label class="bse-field">
                                <span>Текст сообщения</span>
                                <textarea class="bse-input" rows="4" x-model="selected.text"
                                          placeholder="Что увидит пользователь"></textarea>
                            </label>
                        </template>

                        <template x-if="selected.type === 'ai'">
                            <p class="bse-note">Управление передаётся AI-ассистенту: сбор данных и уточняющие вопросы.
                                После завершения пользователь идёт по выходу «Продолжить».</p>
                        </template>

                        <template x-if="selected.type === 'list'">
                            <label class="bse-field">
                                <span>Надпись на кнопке списка</span>
                                <input type="text" class="bse-input" x-model="selected.button" maxlength="20">
                            </label>
                        </template>

                        <template x-if="selected.type === 'buttons' || selected.type === 'list'">
                            <div class="bse-field">
                                <span x-text="selected.type === 'buttons'
                                    ? `Кнопки (${selected.options.length} из ${optionLimit.buttons})`
                                    : `Элементы списка (${selected.options.length} из ${optionLimit.list})`"></span>
                                <template x-for="opt in selected.options" :key="opt.id">
                                    <div class="bse-option-row">
                                        <input type="text" class="bse-input" x-model="opt.title"
                                               x-bind:maxlength="selected.type === 'buttons' ? 20 : 24"
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
                                <p class="bse-note">Лимит WhatsApp: до 3 кнопок (до 20 символов) или до 10 элементов списка (до 24 символов).</p>
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
                            <p class="bse-note">Точка входа: с этого блока начинается каждый новый диалог.
                                Блок единственный и не удаляется.</p>
                        </template>
                    </div>
                </template>

                <template x-if="!selected">
                    <div class="bse-panel-body">
                        <p class="bse-note">Выберите блок на холсте, чтобы изменить его текст и варианты ответа.</p>
                        <p class="bse-note">Связи: кликните оранжевый кружок справа от выхода, затем кликните по
                            следующему блоку (или просто потяните от кружка к блоку).
                            Чтобы разорвать связь, потяните её с выхода в пустое место.</p>
                    </div>
                </template>
            </aside>
        </div>

        <div class="bse-actions">
            <button type="button" class="bse-btn bse-btn-lg" x-on:click="save()" x-bind:disabled="busy">
                Сохранить черновик
            </button>
            <button type="button" class="bse-btn bse-btn-lg bse-btn-primary" x-on:click="publish()" x-bind:disabled="busy">
                Опубликовать сценарий
            </button>
        </div>

        <script>
            document.addEventListener('alpine:init', () => {
                Alpine.data('botScenarioEditor', (initial) => ({
                    nodes: (initial.nodes ?? []).map((n) => ({ text: '', options: [], ...n })),
                    edges: initial.edges ?? [],
                    pan: { x: 40, y: 20 },
                    scale: 1,
                    selectedId: null,
                    search: '',
                    busy: false,
                    drag: null,
                    linking: null,
                    linkLine: null,

                    NODE_W: 260,
                    HEADER_H: 32,
                    TEXT_H: 40,
                    OUT_H: 28,

                    typeLabels: { start: 'Старт', text: 'Текст', buttons: 'Меню (кнопки)', list: 'Список', ai: 'Запрос ввода (AI)' },
                    optionLimit: { buttons: 3, list: 10 },

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

                        return [{ key: 'continue', label: 'Продолжить' }]
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

                    edgePath(edge) {
                        const a = this.portPos(edge.from, edge.output)
                        const b = this.inputPos(edge.to)
                        if (! a || ! b) return ''
                        const dx = Math.max(50, Math.abs(b.x - a.x) / 2)

                        return `M ${a.x} ${a.y} C ${a.x + dx} ${a.y}, ${b.x - dx} ${b.y}, ${b.x} ${b.y}`
                    },

                    edgesPath() {
                        return this.edges.map((e) => this.edgePath(e)).join(' ')
                    },

                    arrowsPath() {
                        return this.edges.map((e) => {
                            const b = this.inputPos(e.to)

                            return b ? `M ${b.x - 10} ${b.y - 5.5} L ${b.x - 1} ${b.y} L ${b.x - 10} ${b.y + 5.5} Z` : ''
                        }).join(' ')
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

                    nodeLabel(node) {
                        const type = this.typeLabels[node.type] ?? node.type
                        const text = (node.text ?? '').trim()

                        return text ? type + ': ' + text.slice(0, 30) : type
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

                        this.selectedId = node.id
                        this.drag = { kind: 'node', id: node.id, sx: e.clientX, sy: e.clientY, nx: node.x, ny: node.y }
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
                        if (! hit) return
                        const r = this.$refs.viewport.getBoundingClientRect()
                        this.pan.x = r.width / 2 - (hit.x + this.NODE_W / 2) * this.scale
                        this.pan.y = r.height / 2 - (hit.y + 80) * this.scale
                        this.selectedId = hit.id
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
            .bse-arrows { fill: rgb(156 163 175); stroke: none; }
            .bse-edge-temp { stroke: rgb(217 119 6); stroke-dasharray: 6 4; }

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
            .bse-link-target { outline: 2px dashed rgb(217 119 6); outline-offset: 3px; cursor: pointer; }

            .bse-panel {
                width: 20rem; flex-shrink: 0; border: 1px solid rgb(229 231 235); border-radius: 0.75rem;
                background: white; overflow-y: auto; max-height: calc(100vh - 22rem); min-height: 560px;
            }
            .bse-panel-body { padding: 1rem; display: flex; flex-direction: column; gap: 0.75rem; }
            .bse-panel-title { font-weight: 600; font-size: 0.9375rem; }
            .bse-field { display: flex; flex-direction: column; gap: 0.375rem; font-size: 0.8125rem; font-weight: 500; color: rgb(55 65 81); }
            .bse-note { font-size: 0.75rem; color: rgb(107 114 128); }
            .bse-option-row { display: flex; gap: 0.375rem; }
            .bse-option-row .bse-input { flex: 1; }

            .bse-input {
                padding: 0.375rem 0.625rem; font-size: 0.875rem; border-radius: 0.5rem; font-weight: 400;
                border: 1px solid rgb(209 213 219); background: white; color: rgb(17 24 39); width: 100%;
            }
            .bse-search { width: 14rem; }
            .bse-actions { display: flex; justify-content: flex-end; gap: 0.5rem; }

            :where(.dark) .bse-toolbar-sep { background: rgb(55 65 81); }
            :where(.dark) .bse-btn { background: rgb(31 41 55); border-color: rgb(75 85 99); color: rgb(209 213 219); }
            :where(.dark) .bse-btn:hover { background: rgb(55 65 81); }
            :where(.dark) .bse-btn-primary { background: rgb(217 119 6); border-color: rgb(217 119 6); color: white; }
            :where(.dark) .bse-btn-danger { color: rgb(252 165 165); border-color: rgb(153 27 27); }
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
        </style>
    </div>
</x-filament-panels::page>
