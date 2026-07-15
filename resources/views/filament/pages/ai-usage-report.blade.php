<x-filament-panels::page>
    {{-- Filament поставляет предкомпилированный CSS без произвольных tailwind-классов
         кастомных страниц, поэтому раскладка отчёта описана локальными стилями. --}}
    <style>
        .aiur { --aiur-muted: #6b7280; --aiur-border: #e5e7eb; --aiur-card: #fff; --aiur-ring: rgb(3 7 18 / .08); }
        .dark .aiur { --aiur-muted: #9ca3af; --aiur-border: rgb(255 255 255 / .08); --aiur-card: #111827; --aiur-ring: rgb(255 255 255 / .10); }

        .aiur { display: flex; flex-direction: column; gap: 1.25rem; }

        .aiur-toolbar { display: flex; align-items: center; gap: .75rem; flex-wrap: wrap; font-size: .875rem; }
        .aiur-toolbar label { font-weight: 500; }
        .aiur-toolbar select { border: 1px solid var(--aiur-border); background: var(--aiur-card); color: inherit; border-radius: .5rem; font-size: .875rem; padding: .4rem 2.2rem .4rem .75rem; }
        .aiur-note { font-size: .8125rem; color: var(--aiur-muted); }

        .aiur-subhead { font-size: 1rem; font-weight: 600; margin-top: .25rem; }
        .aiur-subhead .aiur-note { font-weight: 400; margin-left: .5rem; }

        .aiur-cards { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; }
        @media (min-width: 768px) { .aiur-cards { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
        .aiur-card { display: flex; flex-direction: column; gap: .15rem; background: var(--aiur-card); border: 1px solid var(--aiur-ring); border-radius: .75rem; padding: .9rem 1.1rem; box-shadow: 0 1px 2px rgb(0 0 0 / .04); }
        .aiur-kpi-label { font-size: .8125rem; color: var(--aiur-muted); }
        .aiur-kpi-value { font-size: 1.5rem; font-weight: 600; line-height: 1.2; }
        .aiur-kpi-note { font-size: .75rem; color: var(--aiur-muted); }

        {{-- Пары компактных таблиц на широких экранах встают в две колонки. --}}
        .aiur-duo { display: grid; grid-template-columns: minmax(0, 1fr); gap: 1.25rem; align-items: start; }
        @media (min-width: 1280px) { .aiur-duo { grid-template-columns: repeat(2, minmax(0, 1fr)); } }

        .aiur-table { width: 100%; font-size: .875rem; border-collapse: collapse; }
        .aiur-table th { text-align: left; font-weight: 500; color: var(--aiur-muted); padding: 0 .75rem .5rem 0; white-space: nowrap; }
        .aiur-table td { padding: .45rem .75rem .45rem 0; border-top: 1px solid var(--aiur-border); }
        .aiur-table th:last-child, .aiur-table td:last-child { padding-right: 0; }
        .aiur-table .is-num { text-align: right; font-variant-numeric: tabular-nums; }
        .aiur-table .is-muted { color: var(--aiur-muted); }

        .aiur-mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .8125rem; }
        .aiur-warn { color: #d97706; }
        .aiur-danger { color: #dc2626; }
    </style>

    @php
        $summary = $this->summary();
        $whatsapp = $this->whatsappSummary();
    @endphp

    <div class="aiur">
        <div class="aiur-toolbar">
            <label for="ai-usage-days">Период:</label>
            <select id="ai-usage-days" wire:model.live="days">
                @foreach ($this->periods as $period)
                    <option value="{{ $period }}">{{ $period }} дней</option>
                @endforeach
            </select>
            <span class="aiur-note">Суммы — оценка по сохранённым тарифам (config/ai-pricing.php и config/whatsapp-pricing.php), не биллинг провайдера.</span>
        </div>

        <div class="aiur-subhead">AI</div>

        <div class="aiur-cards">
            <div class="aiur-card">
                <div class="aiur-kpi-label">Запросов</div>
                <div class="aiur-kpi-value">{{ $summary['requests'] }}</div>
                <div class="aiur-kpi-note">{{ number_format($summary['input_tokens']) }} вх. / {{ number_format($summary['output_tokens']) }} исх. токенов</div>
            </div>
            <div class="aiur-card">
                <div class="aiur-kpi-label">Ошибок</div>
                <div class="aiur-kpi-value {{ $summary['errors'] > 0 ? 'aiur-danger' : '' }}">{{ $summary['errors'] }}</div>
            </div>
            <div class="aiur-card">
                <div class="aiur-kpi-label">Среднее время ответа</div>
                <div class="aiur-kpi-value">{{ $summary['avg_latency_ms'] !== null ? $summary['avg_latency_ms'].' мс' : '—' }}</div>
            </div>
            <div class="aiur-card">
                <div class="aiur-kpi-label">Расходы (оценка)</div>
                <div class="aiur-kpi-value">${{ $summary['cost_usd'] }}</div>
                @if ($summary['unknown_cost'] > 0)
                    <div class="aiur-kpi-note aiur-warn">{{ $summary['unknown_cost'] }} вызов(ов) без тарифа — не входят в сумму</div>
                @endif
            </div>
        </div>

        <x-filament::section heading="По дням">
            <table class="aiur-table">
                <thead>
                    <tr>
                        <th>Дата</th><th class="is-num">Запросов</th><th class="is-num">Ошибок</th><th class="is-num">Токенов</th><th class="is-num">Расход, $</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->byDay() as $row)
                        <tr>
                            <td>{{ $row->day }}</td>
                            <td class="is-num">{{ $row->requests }}</td>
                            <td class="is-num">{{ $row->errors }}</td>
                            <td class="is-num">{{ number_format($row->tokens) }}</td>
                            <td class="is-num">{{ number_format((float) $row->cost, 4) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="is-muted">За период вызовов не было.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-filament::section>

        <x-filament::section heading="По моделям">
            <table class="aiur-table">
                <thead>
                    <tr>
                        <th>Модель</th><th class="is-num">Запросов</th><th class="is-num">Ошибок</th><th class="is-num">Ср. время</th><th class="is-num">Токены (вх/исх)</th><th class="is-num">Расход, $</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->byModel() as $row)
                        <tr>
                            <td class="aiur-mono">{{ $row->model }}</td>
                            <td class="is-num">{{ $row->requests }}</td>
                            <td class="is-num">{{ $row->errors }}</td>
                            <td class="is-num">{{ $row->avg_latency !== null ? (int) $row->avg_latency.' мс' : '—' }}</td>
                            <td class="is-num">{{ number_format($row->input_tokens) }} / {{ number_format($row->output_tokens) }}</td>
                            <td class="is-num">{{ number_format((float) $row->cost, 4) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="is-muted">За период вызовов не было.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-filament::section>

        <div class="aiur-duo">
            <x-filament::section heading="По функциям">
                <table class="aiur-table">
                    <thead>
                        <tr>
                            <th>Функция</th><th class="is-num">Запросов</th><th class="is-num">Ошибок</th><th class="is-num">Ср. время</th><th class="is-num">Расход, $</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->byOperation() as $row)
                            <tr>
                                <td>{{ $row->label }}</td>
                                <td class="is-num">{{ $row->requests }}</td>
                                <td class="is-num">{{ $row->errors }}</td>
                                <td class="is-num">{{ $row->avg_latency !== null ? (int) $row->avg_latency.' мс' : '—' }}</td>
                                <td class="is-num">{{ number_format((float) $row->cost, 4) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="is-muted">За период вызовов не было.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-filament::section>

            <x-filament::section heading="Топ контактов по расходам">
                <table class="aiur-table">
                    <thead>
                        <tr>
                            <th>Контакт</th><th class="is-num">Запросов</th><th class="is-num">Расход, $</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->topContacts() as $row)
                            <tr>
                                <td>{{ $row->profile_name !== '' ? $row->profile_name.' · ' : '' }}+{{ $row->phone }}</td>
                                <td class="is-num">{{ $row->requests }}</td>
                                <td class="is-num">{{ number_format((float) $row->cost, 4) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="is-muted">За период вызовов не было.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-filament::section>
        </div>

        <div class="aiur-subhead">
            WhatsApp
            <span class="aiur-note">Шаблонные сообщения: в суммы входят только доставленные/прочитанные — Meta списывает за доставленное сообщение.</span>
        </div>

        <div class="aiur-cards">
            <div class="aiur-card">
                <div class="aiur-kpi-label">Шаблонов отправлено</div>
                <div class="aiur-kpi-value">{{ $whatsapp['sent'] }}</div>
            </div>
            <div class="aiur-card">
                <div class="aiur-kpi-label">Доставлено</div>
                <div class="aiur-kpi-value">{{ $whatsapp['billable'] }}</div>
            </div>
            <div class="aiur-card">
                <div class="aiur-kpi-label">Ошибок доставки</div>
                <div class="aiur-kpi-value {{ $whatsapp['failed'] > 0 ? 'aiur-danger' : '' }}">{{ $whatsapp['failed'] }}</div>
            </div>
            <div class="aiur-card">
                <div class="aiur-kpi-label">Расходы на шаблоны (оценка)</div>
                <div class="aiur-kpi-value">${{ $whatsapp['cost_usd'] }}</div>
                @if ($whatsapp['unknown_cost'] > 0)
                    <div class="aiur-kpi-note aiur-warn">{{ $whatsapp['unknown_cost'] }} сообщение(й) без тарифа — не входят в сумму</div>
                @endif
            </div>
        </div>

        <div class="aiur-duo">
            <x-filament::section heading="Шаблоны по дням">
                <table class="aiur-table">
                    <thead>
                        <tr>
                            <th>Дата</th><th class="is-num">Отправлено</th><th class="is-num">Доставлено</th><th class="is-num">Ошибок</th><th class="is-num">Расход, $</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->whatsappByDay() as $row)
                            <tr>
                                <td>{{ $row->day }}</td>
                                <td class="is-num">{{ $row->sent }}</td>
                                <td class="is-num">{{ $row->billable }}</td>
                                <td class="is-num">{{ $row->failed }}</td>
                                <td class="is-num">{{ number_format((float) $row->cost, 4) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="is-muted">За период шаблонов не отправлялось.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-filament::section>

            <x-filament::section heading="По шаблонам">
                <table class="aiur-table">
                    <thead>
                        <tr>
                            <th>Шаблон</th><th>Категория</th><th class="is-num">Отправлено</th><th class="is-num">Доставлено</th><th class="is-num">Расход, $</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->whatsappByTemplate() as $row)
                            <tr>
                                <td class="aiur-mono">{{ $row->name }}</td>
                                <td>{{ $row->category_label }}</td>
                                <td class="is-num">{{ $row->sent }}</td>
                                <td class="is-num">{{ $row->billable }}</td>
                                <td class="is-num">{{ number_format((float) $row->cost, 4) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="is-muted">За период шаблонов не отправлялось.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-filament::section>
        </div>

        <x-filament::section heading="Сообщения по дням">
            <table class="aiur-table">
                <thead>
                    <tr>
                        <th>Дата</th><th class="is-num">Входящих</th><th class="is-num">Исходящих</th><th class="is-num">Шаблонов</th><th class="is-num">Ошибок доставки</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->messagesByDay() as $row)
                        <tr>
                            <td>{{ $row->day }}</td>
                            <td class="is-num">{{ $row->inbound }}</td>
                            <td class="is-num">{{ $row->outbound }}</td>
                            <td class="is-num">{{ $row->templates }}</td>
                            <td class="is-num">{{ $row->failed }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="is-muted">За период сообщений не было.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-filament::section>
    </div>
</x-filament-panels::page>
