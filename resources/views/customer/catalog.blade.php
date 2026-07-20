<x-customer.layout title="Каталог объявлений">
    <h1>Каталог объявлений</h1>
    <p class="muted" style="margin: 0 0 1rem;">Спецтехника и услуги — все опубликованные объявления.</p>

    <form method="GET" action="{{ url()->current() }}" class="card">
        {{-- The personal link's signature covers only the path and expiry, so the form can change every filter freely. --}}
        <input type="hidden" name="expires" value="{{ $expires }}">
        <input type="hidden" name="signature" value="{{ $signature }}">

        <div class="field">
            <label for="q">Поиск</label>
            <input id="q" name="q" value="{{ $filters['q'] }}" placeholder="Что ищете? Например: кран 25 тонн">
        </div>

        <div class="filter-row">
            <div class="field">
                <label for="category_id">Категория</label>
                <select id="category_id" name="category_id">
                    <option value="">— все категории —</option>
                    @foreach (\App\Enums\ListingType::cases() as $categoryType)
                        @if ($categories->where('type', $categoryType)->isNotEmpty())
                            <optgroup label="{{ $categoryType->getLabel() }}">
                                @foreach ($categories->where('type', $categoryType) as $category)
                                    <option value="{{ $category->id }}" @selected($filters['category']?->id === $category->id)>{{ $category->name }}</option>
                                @endforeach
                            </optgroup>
                        @endif
                    @endforeach
                </select>
            </div>

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

    <p class="muted" style="margin: 0 0 1rem;">Найдено объявлений: {{ $listings->total() }}</p>

    @forelse ($listings as $listing)
        <div class="card listing-card">
            @if ($listing->photos->isNotEmpty())
                <img class="thumb" src="{{ $listing->photos->first()->url() }}" alt="Фото объявления">
            @endif
            <div class="listing-body">
                <h2 class="listing-title">{{ $listing->displayName() ?: 'Объявление №'.$listing->id }}</h2>
                <p class="listing-line muted">
                    {{ collect([$listing->type->getLabel(), $listing->category?->name, $listing->brand?->name])->filter()->unique()->implode(' · ') }}
                </p>
                @if ($listing->description)
                    <p class="listing-line">{{ \Illuminate\Support\Str::limit($listing->description, 140) }}</p>
                @endif
                <p class="listing-line">
                    {{ collect([$listing->locationLine(), $listing->price])->filter()->implode(' · ') }}
                </p>
                @if ($listing->supplier->displayName())
                    <p class="listing-line muted">Поставщик: {{ $listing->supplier->displayName() }}</p>
                @endif

                @if (in_array($listing->id, $requestedListingIds, true))
                    <div class="actions"><span class="badge badge-green">Заявка отправлена — ждём ответа поставщика</span></div>
                @else
                    <form method="POST" action="{{ $selectUrls[$listing->id] }}" class="actions">
                        @csrf
                        {{-- The current filter state rides along so the confirmation returns to the same catalog page. --}}
                        <input type="hidden" name="q" value="{{ $filters['q'] }}">
                        <input type="hidden" name="category_id" value="{{ $filters['category']?->id }}">
                        <input type="hidden" name="location_id" value="{{ $filters['location']?->id }}">
                        <input type="hidden" name="sort" value="{{ $filters['sort'] }}">
                        <input type="hidden" name="page" value="{{ $listings->currentPage() }}">
                        <button type="submit" class="btn btn-primary">Выбрать</button>
                    </form>
                @endif
            </div>
        </div>
    @empty
        <div class="card">
            <p style="margin: 0;">Ничего не нашлось. Измените запрос или сбросьте фильтры.</p>
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
