<x-supplier.layout :title="$editable ? 'Редактирование объявления' : 'Ваше объявление'">
    <a class="back" href="{{ $indexUrl }}">&larr; Мои объявления</a>

    <div class="card">
        <div class="meta">
            <h1 style="margin: 0;">{{ $editable ? 'Редактирование объявления' : 'Ваше объявление' }}</h1>
            <x-supplier.status-badge :status="$listing->status" />
        </div>

        @if ($listing->status === \App\Enums\ListingStatus::Rejected && $listing->rejection_reason)
            <p class="reason">Причина отклонения: {{ $listing->rejection_reason }}</p>
        @endif

        @if ($editable)
            <p class="muted" style="margin: 0.75rem 0 1rem;">Проверьте данные и заполните недостающее — после сохранения объявление уйдёт на проверку модератору.</p>

            <form method="POST" action="{{ $updateUrl }}">
                @csrf

                <div class="field">
                    <label for="type">Тип</label>
                    <select id="type" name="type">
                        @foreach (\App\Enums\ListingType::cases() as $type)
                            <option value="{{ $type->value }}" @selected(old('type', $listing->type->value) === $type->value)>{{ $type->getLabel() }}</option>
                        @endforeach
                    </select>
                    @error('type') <p class="error">{{ $message }}</p> @enderror
                </div>

                <div class="field">
                    <label for="category">Категория</label>
                    <input id="category" name="category" value="{{ old('category', $listing->category) }}" placeholder="Например: автокран, сварщик">
                    @error('category') <p class="error">{{ $message }}</p> @enderror
                </div>

                <div class="field">
                    <label for="description">Описание</label>
                    <textarea id="description" name="description" rows="4" placeholder="Что предлагаете, характеристики, условия">{{ old('description', $listing->description) }}</textarea>
                    @error('description') <p class="error">{{ $message }}</p> @enderror
                </div>

                <div class="field">
                    <label for="location">Локация</label>
                    <input id="location" name="location" value="{{ old('location', $listing->location) }}" placeholder="Например: Шымкент, центр">
                    @error('location') <p class="error">{{ $message }}</p> @enderror
                </div>

                <div class="field">
                    <label for="price">Цена / тариф</label>
                    <input id="price" name="price" value="{{ old('price', $listing->price) }}" placeholder="Например: 10000 тг/ч">
                    @error('price') <p class="error">{{ $message }}</p> @enderror
                </div>

                <div class="actions">
                    <button type="submit" class="btn btn-primary">Сохранить и отправить на проверку</button>
                </div>
            </form>
        @else
            @if ($listing->status === \App\Enums\ListingStatus::PendingModeration)
                <p class="muted" style="margin: 0.75rem 0 0;">Объявление на проверке у модератора — редактирование недоступно, ожидайте результата.</p>
            @elseif ($listing->status === \App\Enums\ListingStatus::Published && $listing->expires_at)
                <p class="muted" style="margin: 0.75rem 0 0;">Опубликовано до {{ $listing->expires_at->format('d.m.Y') }}.</p>
            @elseif ($listing->status === \App\Enums\ListingStatus::Archived)
                <p class="muted" style="margin: 0.75rem 0 0;">Объявление в архиве и не участвует в поиске. Чтобы разместить его снова, создайте новое объявление в WhatsApp.</p>
            @endif

            <dl style="margin: 1rem 0 0;">
                <dt>Тип</dt>
                <dd>{{ $listing->type->getLabel() }}</dd>
                <dt>Категория</dt>
                <dd>{{ $listing->category ?: '—' }}</dd>
                <dt>Описание</dt>
                <dd>{{ $listing->description ?: '—' }}</dd>
                <dt>Локация</dt>
                <dd>{{ $listing->location ?: '—' }}</dd>
                <dt>Цена / тариф</dt>
                <dd>{{ $listing->price ?: '—' }}</dd>
            </dl>

            @if ($archiveUrl)
                <form method="POST" action="{{ $archiveUrl }}" class="actions">
                    @csrf
                    <button type="submit" class="btn btn-danger">Снять с публикации</button>
                </form>
            @endif
        @endif

        @if ($listing->photos->isNotEmpty())
            <dl style="margin: 1rem 0 0;"><dt>Фотографии</dt></dl>
            <div class="photos">
                @foreach ($listing->photos as $photo)
                    <img src="{{ $photo->url() }}" alt="Фото объявления">
                @endforeach
            </div>
        @endif

        @if ($listing->audioMessages->isNotEmpty())
            <dl style="margin: 1rem 0 0;"><dt>Голосовые сообщения</dt></dl>
            @foreach ($listing->audioMessages as $audio)
                <p class="muted" style="margin: 0.25rem 0 0;">{{ $audio->transcription ?: 'Без транскрипции' }}</p>
            @endforeach
        @endif
    </div>
</x-supplier.layout>
