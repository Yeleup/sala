<x-customer.layout title="Каталог объявлений">
    <header class="page-header">
        <h1>Каталог объявлений</h1>
        {{-- The kind filter has no control of its own, so naming it here is the only thing
             that tells the customer why two thirds of the catalog is missing. --}}
        @if ($filters['kind'])
            <p>{{ $filters['kind']->label() }} — все опубликованные объявления этого вида. <a href="{{ $allKindsUrl }}">Показать все виды</a></p>
        @else
            <p>Спецтехника — все опубликованные объявления.</p>
        @endif
    </header>

    <form method="GET" action="{{ url()->current() }}" class="card">
        {{-- The personal link's signature covers only the path and expiry, so the form can change every filter freely. --}}
        <input type="hidden" name="expires" value="{{ $expires }}">
        <input type="hidden" name="signature" value="{{ $signature }}">
        {{-- The kind has no control of its own: the bot's deep link sets it, the header names it,
             and the form only carries it forward — «Показать все виды» is the one way out. --}}
        @if ($filters['kind'])
            <input type="hidden" name="kind" value="{{ $filters['kind']->value }}">
        @endif

        <div class="field">
            <label for="q">Поиск</label>
            <input id="q" name="q" value="{{ $filters['q'] }}" placeholder="Что ищете? Например: кран 25 тонн">
        </div>

        <div class="filter-row">
            {{-- Only rental listings carry a category: in the other two branches the
                 select could return nothing but nothing. --}}
            @if ($filters['kind'] === null || $filters['kind']->usesCategory())
                <div class="field">
                    <label for="category_id">Категория</label>
                    <select id="category_id" name="category_id">
                        <option value="">— все категории —</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" @selected($filters['category']?->id === $category->id)>{{ $category->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            <x-location-picker label="Место" :value="$filters['location']?->id"
                               :initial-text="$locationLabel" placeholder="Город, район или село" />

            <div class="field">
                <label for="sort">Сортировка</label>
                <select id="sort" name="sort">
                    @if ($filters['q'] !== '')
                        <option value="relevance" @selected($filters['sort'] === 'relevance')>По соответствию запросу</option>
                    @endif
                    <option value="newest" @selected($filters['sort'] === 'newest')>Сначала новые</option>
                    <option value="oldest" @selected($filters['sort'] === 'oldest')>Сначала старые</option>
                </select>
            </div>
        </div>

        <div class="actions" style="margin-top: 0.25rem;">
            <button type="submit" class="btn btn-primary">Показать</button>
            <a class="btn btn-secondary" href="{{ $resetUrl }}">Сбросить</a>
        </div>
    </form>

    <p class="muted result-count">Найдено объявлений: {{ $listings->total() }}</p>

    @forelse ($listings as $listing)
        <div class="card listing-card">
            @if ($listing->photos->isNotEmpty())
                <a class="thumb-link" href="{{ $detailUrls[$listing->id] }}">
                    <img class="thumb" src="{{ $listing->photos->first()->url() }}" alt="Фото объявления">
                    @if ($listing->photos->count() > 1)
                        <span class="thumb-count">{{ $listing->photos->count() }} фото</span>
                    @endif
                </a>
            @endif
            <div class="listing-body">
                {{-- A repair master and a driver are people, not machines: the name opens the card. --}}
                @if ($listing->kind !== \App\Enums\ListingKind::Rental && $listing->person_name)
                    <p class="listing-person">{{ $listing->person_name }}</p>
                @endif
                <h2 class="listing-title"><a class="title-link" href="{{ $detailUrls[$listing->id] }}">{{ $listing->displayName() ?: 'Объявление №'.$listing->id }}</a></h2>
                @if ($listing->kind === \App\Enums\ListingKind::Repair)
                    @if ($listing->services)
                        <p class="listing-line">{{ $listing->services }}</p>
                    @endif
                    @if ($listing->repair_place)
                        <p class="listing-line muted">{{ $listing->repair_place->label() }}</p>
                    @endif
                @elseif ($listing->kind === \App\Enums\ListingKind::Driver)
                    @if ($listing->machineCategories->isNotEmpty())
                        <p class="listing-line muted">{{ $listing->machineCategories->pluck('name')->implode(', ') }}</p>
                    @endif
                    @if ($listing->experience_years !== null)
                        <p class="listing-line">Стаж {{ $listing->experience_years }} лет (со слов исполнителя)</p>
                    @endif
                    @if ($listing->document_verified_at)
                        <div class="card-badge">✅ Документ проверен</div>
                    @endif
                @else
                    @php($meta = collect([$listing->category?->name, $listing->brand?->name])->filter()->unique()->implode(' · '))
                    @if ($meta)
                        <p class="listing-line muted">{{ $meta }}</p>
                    @endif
                @endif
                @if ($listing->description)
                    <p class="listing-line">{{ \Illuminate\Support\Str::limit($listing->description, 140) }}</p>
                @endif
                @if ($listing->locationLine())
                    <p class="listing-line muted">{{ $listing->locationLine() }}</p>
                @endif
                {{-- A driver has no price line at all; a repair price is the diagnostics fee. --}}
                @if ($listing->price && $listing->kind === \App\Enums\ListingKind::Repair)
                    <p class="listing-line listing-price">Диагностика: {{ $listing->price }}</p>
                @elseif ($listing->price && $listing->kind !== \App\Enums\ListingKind::Driver)
                    <p class="listing-line listing-price">{{ $listing->price }}</p>
                @endif
                @if ($listing->supplier->displayName())
                    <p class="listing-line muted">Поставщик: {{ $listing->supplier->displayName() }}</p>
                @endif

                @if (in_array($listing->id, $requestedListingIds, true))
                    <div class="actions">
                        <span class="badge badge-green">Заявка отправлена — ждём ответа поставщика</span>
                        <a class="btn btn-secondary" href="{{ $detailUrls[$listing->id] }}">Подробнее</a>
                    </div>
                @else
                    <form method="POST" action="{{ $selectUrls[$listing->id] }}" class="actions">
                        @csrf
                        {{-- The current filter state rides along so the confirmation returns to the same catalog page. --}}
                        <input type="hidden" name="q" value="{{ $filters['q'] }}">
                        <input type="hidden" name="kind" value="{{ $filters['kind']?->value }}">
                        <input type="hidden" name="category_id" value="{{ $filters['category']?->id }}">
                        <input type="hidden" name="location_id" value="{{ $filters['location']?->id }}">
                        <input type="hidden" name="sort" value="{{ $filters['sort'] }}">
                        <input type="hidden" name="page" value="{{ $listings->currentPage() }}">
                        <button type="submit" class="btn btn-primary">Выбрать</button>
                        <a class="btn btn-secondary" href="{{ $detailUrls[$listing->id] }}">Подробнее</a>
                    </form>
                @endif
            </div>
        </div>
    @empty
        <div class="card empty-state">
            @if ($filters['kind'])
                <p style="margin: 0;">Среди объявлений «{{ $filters['kind']->label() }}» ничего не нашлось. Измените запрос, сбросьте фильтры или посмотрите все виды.</p>
                <p style="margin: 0.75rem 0 0;"><a href="{{ $allKindsUrl }}">Показать все виды</a></p>
            @else
                <p style="margin: 0;">Ничего не нашлось. Измените запрос или сбросьте фильтры.</p>
            @endif
        </div>
    @endforelse

    @if ($listings->hasPages())
        <nav class="pager">
            @if ($listings->onFirstPage())
                <span class="pager-link disabled">&larr; Назад</span>
            @else
                <a class="pager-link" href="{{ $listings->previousPageUrl() }}">&larr; Назад</a>
            @endif

            <span class="muted">Страница {{ $listings->currentPage() }} из {{ $listings->lastPage() }}</span>

            @if ($listings->hasMorePages())
                <a class="pager-link" href="{{ $listings->nextPageUrl() }}">Вперёд &rarr;</a>
            @else
                <span class="pager-link disabled">Вперёд &rarr;</span>
            @endif
        </nav>
    @endif

</x-customer.layout>
