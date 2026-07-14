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
                    <label for="category_id">Категория</label>
                    <select id="category_id" name="category_id">
                        <option value="" @selected(old('category_id', $listing->category_id) === null)>— выберите категорию —</option>
                        @foreach (\App\Enums\ListingType::cases() as $categoryType)
                            @if ($categories->where('type', $categoryType)->isNotEmpty())
                                <optgroup label="{{ $categoryType->getLabel() }}">
                                    @foreach ($categories->where('type', $categoryType) as $category)
                                        <option value="{{ $category->id }}" @selected((int) old('category_id', $listing->category_id) === $category->id)>{{ $category->name }}</option>
                                    @endforeach
                                </optgroup>
                            @endif
                        @endforeach
                    </select>
                    <p class="muted" style="margin: 0.25rem 0 0;">Категория должна соответствовать выбранному типу.</p>
                    @error('category_id') <p class="error">{{ $message }}</p> @enderror
                </div>

                <div class="field">
                    <label for="description">Описание</label>
                    <textarea id="description" name="description" rows="4" placeholder="Что предлагаете, характеристики, условия">{{ old('description', $listing->description) }}</textarea>
                    @error('description') <p class="error">{{ $message }}</p> @enderror
                </div>

                <div class="field">
                    <label for="location_search">Локация</label>
                    <input id="location_search" name="location_label" list="location-options" autocomplete="off"
                           value="{{ old('location_label', $listing->location?->label()) }}"
                           placeholder="Начните вводить: город, район или село">
                    <datalist id="location-options"></datalist>
                    <input type="hidden" id="location_id" name="location_id" value="{{ old('location_id', $listing->location_id) }}">
                    <p class="muted" style="margin: 0.25rem 0 0;">Выберите вариант из подсказок.</p>
                    @error('location_id') <p class="error">{{ $message }}</p> @enderror
                </div>

                <div class="field">
                    <label for="location_detail">Уточнение адреса (необязательно)</label>
                    <input id="location_detail" name="location_detail" value="{{ old('location_detail', $listing->location_detail) }}" placeholder="Например: центр, мкр Нурсат">
                    @error('location_detail') <p class="error">{{ $message }}</p> @enderror
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

            <script>
                (function () {
                    const input = document.getElementById('location_search');
                    const datalist = document.getElementById('location-options');
                    const hidden = document.getElementById('location_id');
                    let optionIds = {};
                    let timer = null;

                    input.addEventListener('input', function () {
                        hidden.value = optionIds[input.value] ?? '';

                        if (hidden.value !== '') {
                            return;
                        }

                        clearTimeout(timer);
                        const query = input.value.trim();

                        if (query.length < 2) {
                            return;
                        }

                        timer = setTimeout(function () {
                            fetch('{{ route('locations.search') }}?q=' + encodeURIComponent(query))
                                .then((response) => response.json())
                                .then((items) => {
                                    optionIds = {};
                                    datalist.innerHTML = '';
                                    items.forEach((item) => {
                                        optionIds[item.label] = item.id;
                                        const option = document.createElement('option');
                                        option.value = item.label;
                                        datalist.appendChild(option);
                                    });
                                    hidden.value = optionIds[input.value] ?? '';
                                });
                        }, 200);
                    });
                })();
            </script>
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
                <dd>{{ $listing->category?->name ?: '—' }}</dd>
                <dt>Описание</dt>
                <dd>{{ $listing->description ?: '—' }}</dd>
                <dt>Локация</dt>
                <dd>{{ $listing->locationLine() ?: '—' }}</dd>
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
