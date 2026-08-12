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
                    <label for="title">Название</label>
                    <input id="title" name="title" maxlength="255" value="{{ old('title', $listing->title) }}"
                           placeholder="{{ match ($listing->kind) {
                               \App\Enums\ListingKind::Rental => 'Например: Аренда автокрана 25 т',
                               \App\Enums\ListingKind::Repair => 'Например: Ремонт спецтехники',
                               \App\Enums\ListingKind::Driver => 'Например: Машинист экскаватора',
                           } }}">
                    @error('title') <p class="error">{{ $message }}</p> @enderror
                </div>

                @if ($listing->kind === \App\Enums\ListingKind::Rental)
                    <div class="field">
                        <label for="category_id">Категория</label>
                        <select id="category_id" name="category_id">
                            <option value="" @selected(old('category_id', $listing->category_id) === null)>— выберите категорию —</option>
                            @foreach ($categories as $category)
                                <option value="{{ $category->id }}" @selected((int) old('category_id', $listing->category_id) === $category->id)>{{ $category->name }}</option>
                            @endforeach
                        </select>
                        @error('category_id') <p class="error">{{ $message }}</p> @enderror
                    </div>

                    @if ($brands->isNotEmpty())
                        <div class="field">
                            <label for="brand_id">Марка (необязательно)</label>
                            <select id="brand_id" name="brand_id">
                                <option value="" @selected(old('brand_id', $listing->brand_id) === null)>— без марки —</option>
                                @foreach ($brands as $brand)
                                    <option value="{{ $brand->id }}" @selected((int) old('brand_id', $listing->brand_id) === $brand->id)>{{ $brand->name }}</option>
                                @endforeach
                            </select>
                            <p class="muted" style="margin: 0.25rem 0 0;">Производитель техники.</p>
                            @error('brand_id') <p class="error">{{ $message }}</p> @enderror
                        </div>
                    @endif
                @elseif ($listing->kind === \App\Enums\ListingKind::Repair)
                    <div class="field">
                        <label for="person_name">Имя или название сервиса</label>
                        <input id="person_name" name="person_name" maxlength="255" value="{{ old('person_name', $listing->person_name) }}" placeholder="Например: Сервис «Мотор» или Асхат">
                        @error('person_name') <p class="error">{{ $message }}</p> @enderror
                    </div>

                    <div class="field">
                        <label for="services">Услуги</label>
                        <textarea id="services" name="services" rows="3" placeholder="Например: диагностика, ремонт двигателя, гидравлика, электрика">{{ old('services', $listing->services) }}</textarea>
                        @error('services') <p class="error">{{ $message }}</p> @enderror
                    </div>

                    <div class="field">
                        <label for="repair_place">Где выполняете ремонт</label>
                        <select id="repair_place" name="repair_place">
                            <option value="" @selected(old('repair_place', $listing->repair_place?->value) === null)>— выберите вариант —</option>
                            @foreach ($repairPlaces as $place)
                                <option value="{{ $place->value }}" @selected(old('repair_place', $listing->repair_place?->value) === $place->value)>{{ $place->label() }}</option>
                            @endforeach
                        </select>
                        @error('repair_place') <p class="error">{{ $message }}</p> @enderror
                    </div>
                @else
                    <div class="field">
                        <label for="person_name">Имя</label>
                        <input id="person_name" name="person_name" maxlength="255" value="{{ old('person_name', $listing->person_name) }}" placeholder="Например: Серик">
                        @error('person_name') <p class="error">{{ $message }}</p> @enderror
                    </div>

                    <div class="field">
                        <label>Техника, на которой работаете</label>
                        {{-- Чекбоксы вместо select multiple: на телефоне мультиселект неудобен. --}}
                        <div style="max-height: 13rem; overflow-y: auto; border: 1px solid #cbd5e1; border-radius: 0.625rem; background: #fff; padding: 0.25rem 0.75rem;">
                            @foreach ($categories as $category)
                                <label style="display: flex; align-items: center; gap: 0.5rem; margin: 0; padding: 0.4375rem 0; font-size: 0.9375rem; font-weight: 400; letter-spacing: normal; text-transform: none; color: #1e293b; cursor: pointer;">
                                    {{-- (array): old() отдаёт скаляр, если machine_categories подделали не-массивом. --}}
                                    <input type="checkbox" name="machine_categories[]" value="{{ $category->id }}" style="width: auto; margin: 0; accent-color: #2563eb;"
                                           @checked(in_array($category->id, array_map('intval', (array) old('machine_categories', $machineCategoryIds)), true))>
                                    {{ $category->name }}
                                </label>
                            @endforeach
                        </div>
                        @error('machine_categories') <p class="error">{{ $message }}</p> @enderror
                        @error('machine_categories.*') <p class="error">{{ $message }}</p> @enderror
                    </div>

                    <div class="field">
                        <label for="licence_type">Тип удостоверения</label>
                        <select id="licence_type" name="licence_type">
                            <option value="" @selected(old('licence_type', $listing->licence_type?->value) === null)>— выберите тип —</option>
                            @foreach ($licenceTypes as $type)
                                <option value="{{ $type->value }}" @selected(old('licence_type', $listing->licence_type?->value) === $type->value)>{{ $type->label() }}</option>
                            @endforeach
                        </select>
                        @error('licence_type') <p class="error">{{ $message }}</p> @enderror
                    </div>

                    <div class="field">
                        <label for="experience_years">Стаж, лет</label>
                        <input id="experience_years" name="experience_years" type="number" min="0" max="80" inputmode="numeric" value="{{ old('experience_years', $listing->experience_years) }}" placeholder="Например: 8">
                        @error('experience_years') <p class="error">{{ $message }}</p> @enderror
                    </div>

                    <div class="field">
                        {{-- Скрытый ноль: снятый чекбокс — это ответ «не готов», а не пропуск поля. --}}
                        <input type="hidden" name="travels_to_other_cities" value="0">
                        <label style="display: flex; align-items: center; gap: 0.5rem; margin: 0; font-size: 0.9375rem; font-weight: 400; letter-spacing: normal; text-transform: none; color: #1e293b; cursor: pointer;">
                            <input type="checkbox" name="travels_to_other_cities" value="1" style="width: auto; margin: 0; accent-color: #2563eb;"
                                   @checked((bool) old('travels_to_other_cities', $listing->travels_to_other_cities))>
                            Готов выезжать в другие города
                        </label>
                        @error('travels_to_other_cities') <p class="error">{{ $message }}</p> @enderror
                    </div>
                @endif

                <div class="field">
                    <label for="description">{{ $listing->kind === \App\Enums\ListingKind::Rental ? 'Описание' : 'Описание (необязательно)' }}</label>
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

                @if ($listing->kind !== \App\Enums\ListingKind::Driver)
                    <div class="field">
                        <label for="price">{{ $listing->kind === \App\Enums\ListingKind::Repair ? 'Цена за диагностику (необязательно)' : 'Цена / тариф' }}</label>
                        <input id="price" name="price" value="{{ old('price', $listing->price) }}" placeholder="Например: 10000 тг/ч">
                        @error('price') <p class="error">{{ $message }}</p> @enderror
                    </div>
                @else
                    <div class="field">
                        <label for="document">Фото удостоверения</label>
                        @if ($hasDocument)
                            <p class="muted" style="margin: 0 0 0.375rem;">Документ загружен. Загрузите новый файл, чтобы заменить (проверка будет выполнена заново).</p>
                        @endif
                        <input type="file" id="document" name="document" accept="image/jpeg,image/png,image/webp">
                        <p class="muted" style="margin: 0.25rem 0 0;">Снимок увидит только оператор — в объявлении он не показывается.</p>
                        @error('document') <p class="error">{{ $message }}</p> @enderror
                    </div>
                @endif

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
                        <div class="upload-actions">
                            {{-- Декоративная кнопка: клик проходит сквозь неё к невидимому инпуту галереи, накрывающему зону. --}}
                            <span class="btn btn-primary upload-choose" aria-hidden="true">Выбрать фото</span>
                            <label class="btn btn-secondary upload-camera">
                                Снять на камеру
                                {{-- Без name: скрипт переносит кадры в общий выбор; при недоступном DataTransfer name ставится как запасной путь. --}}
                                <input type="file" id="photos-camera" accept="image/jpeg,image/png,image/webp" capture="environment">
                            </label>
                        </div>
                        <span class="upload-hint">или перетащите файлы сюда</span>
                        <span class="upload-count" id="upload-count" role="status" hidden></span>
                        <button type="button" class="upload-clear" id="upload-clear" hidden>очистить выбор</button>
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
                    const input = document.getElementById('photos');
                    const camera = document.getElementById('photos-camera');
                    const count = document.getElementById('upload-count');
                    const clear = document.getElementById('upload-clear');
                    const warning = document.getElementById('upload-warning');
                    const maxBytes = @json(\App\Models\ListingMedia::MAX_PHOTO_KILOBYTES * 1024);
                    const maxMegabytes = @json(\App\Models\ListingMedia::MAX_PHOTO_KILOBYTES / 1024);
                    const maxPhotos = @json(\App\Models\Listing::MAX_PHOTOS);
                    const acceptedTypes = ['image/jpeg', 'image/png', 'image/webp'];
                    const removeBoxes = Array.from(document.querySelectorAll('.photo-tile input[type="checkbox"]'));

                    // Общий выбор: галерея и камера складываются сюда, чтобы можно
                    // было снять несколько кадров подряд — file-input сам по себе
                    // хранит только последний выбор.
                    let selected = [];

                    function fileNoun(n) {
                        const mod100 = n % 100;
                        const mod10 = n % 10;

                        if (mod10 === 1 && mod100 !== 11) {
                            return 'файл';
                        }

                        return mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14) ? 'файла' : 'файлов';
                    }

                    function render(warningText) {
                        count.hidden = selected.length === 0;
                        clear.hidden = selected.length === 0;
                        count.textContent = 'Выбрано: ' + selected.length + ' ' + fileNoun(selected.length);
                        warning.hidden = warningText === '';
                        warning.textContent = warningText;
                    }

                    // Сколько новых фото ещё поместится: лимит минус существующие,
                    // не отмеченные к удалению.
                    function capacity() {
                        const kept = removeBoxes.filter(function (box) { return !box.checked; }).length;

                        return Math.max(0, maxPhotos - kept);
                    }

                    function merge(files) {
                        // Файл, который сервер всё равно отклонит (сверх лимита размера,
                        // не изображение, сверх потолка в 10 фото), в выбор не попадает:
                        // иначе один такой файл отклонил бы всё сохранение серверной
                        // валидацией — после впустую загруженных мегабайт с телефона.
                        // Сервер остаётся страховкой.
                        const tooBig = [];
                        const wrongType = [];
                        const overLimit = [];

                        Array.from(files).forEach(function (file) {
                            // Пустой type не бракуем: содержимое проверит сервер.
                            if (file.type !== '' && acceptedTypes.indexOf(file.type) === -1) {
                                wrongType.push('«' + file.name + '»');

                                return;
                            }

                            if (file.size > maxBytes) {
                                tooBig.push('«' + file.name + '»');

                                return;
                            }

                            const known = selected.some(function (item) {
                                return item.name === file.name && item.size === file.size && item.lastModified === file.lastModified;
                            });

                            if (known) {
                                return;
                            }

                            if (selected.length >= capacity()) {
                                overLimit.push('«' + file.name + '»');

                                return;
                            }

                            selected.push(file);
                        });

                        const parts = [];

                        if (tooBig.length > 0) {
                            parts.push(tooBig.length === 1
                                ? 'Файл ' + tooBig[0] + ' больше ' + maxMegabytes + ' МБ и не добавлен — уменьшите его или выберите другой.'
                                : 'Файлы ' + tooBig.join(', ') + ' больше ' + maxMegabytes + ' МБ и не добавлены — уменьшите их или выберите другие.');
                        }

                        if (wrongType.length > 0) {
                            parts.push((wrongType.length === 1
                                ? 'Файл ' + wrongType[0] + ' не добавлен'
                                : 'Файлы ' + wrongType.join(', ') + ' не добавлены') + ' — подходят только JPG, PNG и WebP.');
                        }

                        if (overLimit.length > 0) {
                            parts.push('У объявления может быть не более ' + maxPhotos + ' фотографий — ' + (overLimit.length === 1
                                ? 'файл ' + overLimit[0] + ' не добавлен.'
                                : 'файлы ' + overLimit.join(', ') + ' не добавлены.'));
                        }

                        let warningText = parts.join(' ');

                        try {
                            const transfer = new DataTransfer();
                            selected.forEach(function (file) { transfer.items.add(file); });
                            input.files = transfer.files;
                            camera.value = '';
                            camera.removeAttribute('name');
                        } catch (error) {
                            // Без DataTransfer накопление и отсев невозможны: каждый инпут отправляет
                            // свой последний выбор как есть, снятый кадр уходит через запасное имя.
                            camera.files.length ? camera.setAttribute('name', 'photos[]') : camera.removeAttribute('name');
                            selected = Array.from(input.files).concat(Array.from(camera.files));

                            const oversize = selected.filter(function (file) { return file.size > maxBytes; })
                                .map(function (file) { return '«' + file.name + '»'; });
                            warningText = oversize.length === 0 ? '' :
                                'Больше ' + maxMegabytes + ' МБ: ' + oversize.join(', ') + ' — уберите, иначе сохранение не пройдёт.';
                        }

                        render(warningText);
                    }

                    input.addEventListener('change', function () { merge(input.files); });
                    camera.addEventListener('change', function () { merge(camera.files); });

                    // Кнопки поверх невидимого инпута перехватывают и перетаскивание —
                    // без обработчиков дроп на них открыл бы файл вместо страницы формы.
                    const zone = document.querySelector('.upload-zone');
                    zone.addEventListener('dragover', function (event) { event.preventDefault(); });
                    zone.addEventListener('drop', function (event) {
                        event.preventDefault();
                        merge(event.dataTransfer.files);
                    });

                    // Снятая отметка «удалить» сжимает свободное место — набранный
                    // выбор может перестать помещаться в лимит.
                    removeBoxes.forEach(function (box) {
                        box.addEventListener('change', function () {
                            render(selected.length > capacity()
                                ? 'Выбрано больше, чем допускает лимит в ' + maxPhotos + ' фотографий — очистите выбор или отметьте лишние фото к удалению.'
                                : '');
                        });
                    });

                    clear.addEventListener('click', function () {
                        selected = [];
                        input.value = '';
                        camera.value = '';
                        camera.removeAttribute('name');
                        render('');
                    });
                })();
            </script>
        @else
            <dl style="margin: 0;">
                <dt>Название</dt>
                <dd>{{ $listing->title ?: '—' }}</dd>
                @if ($listing->kind === \App\Enums\ListingKind::Rental)
                    <dt>Категория</dt>
                    <dd>{{ $listing->category?->name ?: '—' }}</dd>
                    <dt>Марка</dt>
                    <dd>{{ $listing->brand?->name ?: '—' }}</dd>
                @elseif ($listing->kind === \App\Enums\ListingKind::Repair)
                    <dt>Имя или название сервиса</dt>
                    <dd>{{ $listing->person_name ?: '—' }}</dd>
                    <dt>Услуги</dt>
                    <dd>{{ $listing->services ?: '—' }}</dd>
                    <dt>Где выполняете ремонт</dt>
                    <dd>{{ $listing->repair_place?->label() ?: '—' }}</dd>
                @else
                    <dt>Имя</dt>
                    <dd>{{ $listing->person_name ?: '—' }}</dd>
                    <dt>Техника, на которой работаете</dt>
                    <dd>{{ $listing->machineCategories->pluck('name')->join(', ') ?: '—' }}</dd>
                    <dt>Тип удостоверения</dt>
                    <dd>{{ $listing->licence_type?->label() ?: '—' }}</dd>
                    <dt>Стаж</dt>
                    <dd>{{ $listing->experience_years !== null ? $listing->experience_years.' лет' : '—' }}</dd>
                    <dt>Готовность выезжать</dt>
                    <dd>{{ $listing->travels_to_other_cities === null ? '—' : ($listing->travels_to_other_cities ? 'Готов выезжать в другие города' : 'Работает только в своём городе') }}</dd>
                    <dt>Фото удостоверения</dt>
                    <dd>{{ $hasDocument ? 'Загружено — снимок видит только оператор' : '—' }}</dd>
                @endif
                <dt>Описание</dt>
                <dd>{{ $listing->description ?: '—' }}</dd>
                <dt>Локация</dt>
                <dd>{{ $listing->locationLine() ?: '—' }}</dd>
                @if ($listing->kind === \App\Enums\ListingKind::Rental)
                    <dt>Цена / тариф</dt>
                    <dd>{{ $listing->price ?: '—' }}</dd>
                @elseif ($listing->kind === \App\Enums\ListingKind::Repair)
                    <dt>Цена за диагностику</dt>
                    <dd>{{ $listing->price ?: '—' }}</dd>
                @endif
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
