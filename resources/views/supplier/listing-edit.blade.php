<x-supplier.layout :title="$editable ? 'Редактирование объявления' : 'Ваше объявление'">
    <a class="back" href="{{ $indexUrl }}">&larr; Мои объявления</a>

    <header class="page-header">
        <div class="meta">
            <h1>{{ $editable ? 'Редактирование объявления' : 'Ваше объявление' }}</h1>
            <x-supplier.status-badge :status="$listing->status" />
        </div>
        @if ($editable)
            <p>Проверьте данные и заполните недостающее — после сохранения объявление уйдёт на проверку модератору.</p>
        @elseif ($listing->status === \App\Enums\ListingStatus::PendingModeration)
            <p>Объявление на проверке у модератора — редактирование недоступно, ожидайте результата.</p>
        @elseif ($listing->status === \App\Enums\ListingStatus::Published && $listing->expires_at)
            <p>Опубликовано до {{ $listing->expires_at->format('d.m.Y') }}.</p>
        @elseif ($listing->status === \App\Enums\ListingStatus::Archived)
            <p>Объявление в архиве и не участвует в поиске. Чтобы разместить его снова, создайте новое объявление в WhatsApp.</p>
        @endif
    </header>

    <div class="card">
        @if ($listing->status === \App\Enums\ListingStatus::Rejected && $listing->rejection_reason)
            <p class="reason" style="margin: 0 0 1rem;">Причина отклонения: {{ $listing->rejection_reason }}</p>
        @endif

        @if ($editable)
            <form method="POST" action="{{ $updateUrl }}" enctype="multipart/form-data">
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
                    <label for="title">Название</label>
                    <input id="title" name="title" maxlength="255" value="{{ old('title', $listing->title) }}" placeholder="Например: Аренда автокрана 25 т">
                    @error('title') <p class="error">{{ $message }}</p> @enderror
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

                @if ($brands->isNotEmpty())
                    <div class="field" id="brand-field">
                        <label for="brand_id">Марка (необязательно)</label>
                        <select id="brand_id" name="brand_id">
                            <option value="" @selected(old('brand_id', $listing->brand_id) === null)>— без марки —</option>
                            @foreach ($brands as $brand)
                                <option value="{{ $brand->id }}" @selected((int) old('brand_id', $listing->brand_id) === $brand->id)>{{ $brand->name }}</option>
                            @endforeach
                        </select>
                        <p class="muted" style="margin: 0.25rem 0 0;">Производитель техники; у услуг марки нет.</p>
                        @error('brand_id') <p class="error">{{ $message }}</p> @enderror
                    </div>
                @endif

                <div class="field">
                    <label for="description">Описание</label>
                    <textarea id="description" name="description" rows="4" placeholder="Что предлагаете, характеристики, условия">{{ old('description', $listing->description) }}</textarea>
                    @error('description') <p class="error">{{ $message }}</p> @enderror
                </div>

                <x-location-picker label="Локация" label-name="location_label"
                                   :value="old('location_id', $listing->location_id)"
                                   :initial-text="old('location_label', $listing->location?->label())"
                                   placeholder="Начните вводить: город, район или село">
                    <p class="muted" style="margin: 0.25rem 0 0;">Выберите вариант из подсказок.</p>
                    @error('location_id') <p class="error">{{ $message }}</p> @enderror
                </x-location-picker>

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

                @if ($listing->photos->isNotEmpty())
                    <div class="field">
                        <label>Фотографии</label>
                        <div class="photos">
                            @foreach ($listing->photos as $photo)
                                <label class="photo-tile">
                                    <img src="{{ $photo->url() }}" alt="Фото объявления">
                                    <span class="photo-remove"><input type="checkbox" name="remove_photos[]" value="{{ $photo->id }}" @checked(in_array($photo->id, old('remove_photos', [])))> удалить</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="field">
                    <label for="photos">Добавить фотографии</label>
                    <div class="upload-zone">
                        <input type="file" id="photos" name="photos[]" multiple accept="image/jpeg,image/png,image/webp" aria-describedby="upload-warning">
                        <span class="upload-icon" aria-hidden="true">+</span>
                        <span class="upload-title">Выбрать фото</span>
                        <span class="upload-hint">или перетащите файлы сюда</span>
                        <span class="upload-count" id="upload-count" role="status" hidden></span>
                    </div>
                    <p class="muted" style="margin: 0.25rem 0 0;">До {{ \App\Models\Listing::MAX_PHOTOS }} фото на объявление: JPG, PNG или WebP, каждое до {{ \App\Models\ListingMedia::MAX_PHOTO_KILOBYTES / 1024 }} МБ.</p>
                    <p class="error" id="upload-warning" role="alert" hidden></p>
                    @error('photos') <p class="error">{{ $message }}</p> @enderror
                    @error('photos.*') <p class="error">{{ $message }}</p> @enderror
                </div>

                <div class="actions">
                    <button type="submit" class="btn btn-primary">Сохранить и отправить на проверку</button>
                </div>
            </form>

            <script>
                (function () {
                    const typeSelect = document.getElementById('type');
                    const brandField = document.getElementById('brand-field');

                    if (!brandField) {
                        return;
                    }

                    function toggleBrandField() {
                        const isService = typeSelect.value === @json(\App\Enums\ListingType::Service->value);
                        brandField.style.display = isService ? 'none' : '';

                        if (isService) {
                            document.getElementById('brand_id').value = '';
                        }
                    }

                    typeSelect.addEventListener('change', toggleBrandField);
                    toggleBrandField();
                })();

                (function () {
                    const input = document.getElementById('photos');
                    const count = document.getElementById('upload-count');
                    const warning = document.getElementById('upload-warning');
                    const maxBytes = @json(\App\Models\ListingMedia::MAX_PHOTO_KILOBYTES * 1024);
                    const maxMegabytes = @json(\App\Models\ListingMedia::MAX_PHOTO_KILOBYTES / 1024);

                    function fileNoun(n) {
                        const mod100 = n % 100;
                        const mod10 = n % 10;

                        if (mod10 === 1 && mod100 !== 11) {
                            return 'файл';
                        }

                        return mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14) ? 'файла' : 'файлов';
                    }

                    input.addEventListener('change', function () {
                        const files = Array.from(input.files);

                        count.hidden = files.length === 0;
                        count.textContent = 'Выбрано: ' + files.length + ' ' + fileNoun(files.length);

                        // Подсказка до отправки формы; решает всё равно серверная валидация.
                        // Один файл сверх лимита отклоняет сохранение целиком — формулировка не должна обещать частичный приём.
                        const oversize = files.filter(function (file) { return file.size > maxBytes; });
                        const names = oversize.map(function (file) { return '«' + file.name + '»'; }).join(', ');
                        warning.hidden = oversize.length === 0;
                        warning.textContent = oversize.length === 0 ? '' : (oversize.length === 1
                            ? 'Файл ' + names + ' больше ' + maxMegabytes + ' МБ — выберите фото заново без него, иначе сохранение не пройдёт.'
                            : 'Файлы ' + names + ' больше ' + maxMegabytes + ' МБ — выберите фото заново без них, иначе сохранение не пройдёт.');
                    });
                })();
            </script>
        @else
            <dl style="margin: 0;">
                <dt>Тип</dt>
                <dd>{{ $listing->type->getLabel() }}</dd>
                <dt>Название</dt>
                <dd>{{ $listing->title ?: '—' }}</dd>
                <dt>Категория</dt>
                <dd>{{ $listing->category?->name ?: '—' }}</dd>
                @if ($listing->type === \App\Enums\ListingType::Equipment)
                    <dt>Марка</dt>
                    <dd>{{ $listing->brand?->name ?: '—' }}</dd>
                @endif
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

        @if (! $editable && $listing->photos->isNotEmpty())
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
