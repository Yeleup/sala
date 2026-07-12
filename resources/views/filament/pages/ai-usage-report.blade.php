<x-filament-panels::page>
    @php
        $summary = $this->summary();
    @endphp

    <div class="flex items-center gap-3">
        <label for="ai-usage-days" class="text-sm font-medium">Период:</label>
        <select id="ai-usage-days" wire:model.live="days"
                class="fi-input rounded-lg border-gray-300 text-sm dark:bg-gray-900 dark:border-gray-700">
            @foreach ($this->periods as $period)
                <option value="{{ $period }}">{{ $period }} дней</option>
            @endforeach
        </select>
        <span class="text-sm text-gray-500">Суммы — оценка по сохранённым тарифам (config/ai-pricing.php), не биллинг провайдера.</span>
    </div>

    <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
        <x-filament::section>
            <div class="text-sm text-gray-500">Запросов</div>
            <div class="text-2xl font-semibold">{{ $summary['requests'] }}</div>
            <div class="text-xs text-gray-500">{{ number_format($summary['input_tokens']) }} вх. / {{ number_format($summary['output_tokens']) }} исх. токенов</div>
        </x-filament::section>
        <x-filament::section>
            <div class="text-sm text-gray-500">Ошибок</div>
            <div class="text-2xl font-semibold {{ $summary['errors'] > 0 ? 'text-danger-600' : '' }}">{{ $summary['errors'] }}</div>
        </x-filament::section>
        <x-filament::section>
            <div class="text-sm text-gray-500">Среднее время ответа</div>
            <div class="text-2xl font-semibold">{{ $summary['avg_latency_ms'] !== null ? $summary['avg_latency_ms'].' мс' : '—' }}</div>
        </x-filament::section>
        <x-filament::section>
            <div class="text-sm text-gray-500">Расходы (оценка)</div>
            <div class="text-2xl font-semibold">${{ $summary['cost_usd'] }}</div>
            @if ($summary['unknown_cost'] > 0)
                <div class="text-xs text-warning-600">{{ $summary['unknown_cost'] }} вызов(ов) без тарифа — не входят в сумму</div>
            @endif
        </x-filament::section>
    </div>

    <x-filament::section heading="По дням">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-gray-500">
                    <th class="py-1">Дата</th><th>Запросов</th><th>Ошибок</th><th>Токенов</th><th class="text-right">Расход, $</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->byDay() as $row)
                    <tr class="border-t border-gray-100 dark:border-gray-800">
                        <td class="py-1">{{ $row->day }}</td>
                        <td>{{ $row->requests }}</td>
                        <td>{{ $row->errors }}</td>
                        <td>{{ number_format($row->tokens) }}</td>
                        <td class="text-right">{{ number_format((float) $row->cost, 4) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-2 text-gray-500">За период вызовов не было.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-filament::section>

    <x-filament::section heading="По моделям">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-gray-500">
                    <th class="py-1">Модель</th><th>Запросов</th><th>Ошибок</th><th>Ср. время</th><th>Токены (вх/исх)</th><th class="text-right">Расход, $</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->byModel() as $row)
                    <tr class="border-t border-gray-100 dark:border-gray-800">
                        <td class="py-1 font-mono">{{ $row->model }}</td>
                        <td>{{ $row->requests }}</td>
                        <td>{{ $row->errors }}</td>
                        <td>{{ $row->avg_latency !== null ? (int) $row->avg_latency.' мс' : '—' }}</td>
                        <td>{{ number_format($row->input_tokens) }} / {{ number_format($row->output_tokens) }}</td>
                        <td class="text-right">{{ number_format((float) $row->cost, 4) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-2 text-gray-500">За период вызовов не было.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-filament::section>

    <x-filament::section heading="По функциям">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-gray-500">
                    <th class="py-1">Функция</th><th>Запросов</th><th>Ошибок</th><th>Ср. время</th><th class="text-right">Расход, $</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->byOperation() as $row)
                    <tr class="border-t border-gray-100 dark:border-gray-800">
                        <td class="py-1">{{ $row->label }}</td>
                        <td>{{ $row->requests }}</td>
                        <td>{{ $row->errors }}</td>
                        <td>{{ $row->avg_latency !== null ? (int) $row->avg_latency.' мс' : '—' }}</td>
                        <td class="text-right">{{ number_format((float) $row->cost, 4) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-2 text-gray-500">За период вызовов не было.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-filament::section>

    <x-filament::section heading="Топ контактов по расходам">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-gray-500">
                    <th class="py-1">Контакт</th><th>Запросов</th><th class="text-right">Расход, $</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->topContacts() as $row)
                    <tr class="border-t border-gray-100 dark:border-gray-800">
                        <td class="py-1">{{ $row->profile_name !== '' ? $row->profile_name.' · ' : '' }}+{{ $row->phone }}</td>
                        <td>{{ $row->requests }}</td>
                        <td class="text-right">{{ number_format((float) $row->cost, 4) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="py-2 text-gray-500">За период вызовов не было.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-filament::section>
</x-filament-panels::page>
