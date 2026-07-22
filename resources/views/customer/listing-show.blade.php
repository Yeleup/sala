<x-customer.layout :title="$listing->displayName() ?: 'Объявление №'.$listing->id">
    <a class="back" href="{{ $backUrl }}">&larr; Назад к каталогу</a>

    <header class="page-header">
        <h1>{{ $listing->displayName() ?: 'Объявление №'.$listing->id }}</h1>
        <p>{{ collect([$listing->type->getLabel(), $listing->category?->name, $listing->brand?->name])->filter()->unique()->implode(' · ') }}</p>
    </header>

    <div class="card">
        @if ($listing->photos->isNotEmpty())
            <div class="gallery">
                @foreach ($listing->photos as $photo)
                    <img src="{{ $photo->url() }}" alt="Фото объявления {{ $loop->iteration }} из {{ $loop->count }}">
                @endforeach
            </div>
            @if ($listing->photos->count() > 1)
                <p class="muted gallery-hint">Фотографий: {{ $listing->photos->count() }} — листайте вбок.</p>
            @endif
        @endif

        @if ($listing->description)
            <p class="listing-line prewrap">{{ $listing->description }}</p>
        @endif
        @if ($listing->locationLine())
            <p class="listing-line muted">{{ $listing->locationLine() }}</p>
        @endif
        @if ($listing->price)
            <p class="listing-line listing-price">{{ $listing->price }}</p>
        @endif
        @if ($listing->supplier->displayName())
            <p class="listing-line muted">Поставщик: {{ $listing->supplier->displayName() }}</p>
        @endif

        @if ($alreadyRequested)
            <div class="actions"><span class="badge badge-green">Заявка отправлена — ждём ответа поставщика</span></div>
        @else
            <form method="POST" action="{{ $selectUrl }}" class="actions">
                @csrf
                {{-- The filter state rides along so the confirmation returns to the same catalog page. --}}
                @foreach ($filterState as $name => $value)
                    <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                @endforeach
                <button type="submit" class="btn btn-primary">Выбрать</button>
            </form>
        @endif
    </div>
</x-customer.layout>
