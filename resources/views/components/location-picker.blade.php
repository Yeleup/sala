{{--
    Выпадающий список подсказок для поля «Место» (справочник КАТО):
    название жирным + серая цепочка родителей, управление тапом и
    клавиатурой, по фокусу на пустом поле — области и города. Компонент
    самодостаточен (свои стили и скрипт): страницы, где он живёт,
    открываются из WhatsApp и не зависят от Vite-сборки.

    Пропсы: label — подпись поля; name — имя скрытого поля с id локации;
    label-name — имя текстового инпута (null — текст не отправляется);
    value/initial-text — выбранная локация и её подпись; слот — подсказка
    и вывод ошибки валидации под полем.
--}}
@props([
    'label',
    'name' => 'location_id',
    'labelName' => null,
    'value' => null,
    'initialText' => null,
    'placeholder' => '',
])

@once
<style>
    .location-picker { position: relative; }
    .location-picker .lp-input { padding-right: 2.25rem; }
    .location-picker .lp-clear { position: absolute; top: 0; right: 0; border: 0; background: none; font-size: 1.25rem; line-height: 1; color: #6b7280; cursor: pointer; padding: 0.625rem 0.75rem; }
    .location-picker .lp-list { position: absolute; top: calc(100% + 0.25rem); left: 0; right: 0; z-index: 30; margin: 0; padding: 0.25rem; list-style: none; background: #fff; border: 1px solid #d1d5db; border-radius: 0.5rem; box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1); max-height: 16rem; overflow-y: auto; -webkit-overflow-scrolling: touch; }
    .location-picker .lp-option { padding: 0.5rem 0.625rem; border-radius: 0.375rem; cursor: pointer; }
    /* Цвет подсветки задаётся страницей через --lp-active-bg (каталог заказчика — голубой), по умолчанию — серый. */
    .location-picker .lp-option.active, .location-picker .lp-option:hover { background: var(--lp-active-bg, #f3f4f6); }
    .location-picker .lp-name { display: block; font-weight: 600; font-size: 0.875rem; }
    .location-picker .lp-chain { display: block; color: #6b7280; font-size: 0.8125rem; }
    .location-picker .lp-empty { padding: 0.5rem 0.625rem; color: #6b7280; font-size: 0.875rem; }
</style>
<script>
    function initLocationPicker(root) {
        const input = root.querySelector('.lp-input');
        const hidden = root.querySelector('input[type="hidden"]');
        const clear = root.querySelector('.lp-clear');
        const list = root.querySelector('.lp-list');
        const searchUrl = root.dataset.searchUrl;

        let selectedText = hidden.value !== '' ? input.value : null;
        let topLevel = null;
        let requestSeq = 0;
        let timer = null;
        let activeIndex = -1;
        let items = [];

        function toggleClear() {
            clear.hidden = input.value.trim() === '';
        }

        function close() {
            list.hidden = true;
            input.setAttribute('aria-expanded', 'false');
            input.removeAttribute('aria-activedescendant');
            activeIndex = -1;
        }

        function render(found) {
            items = found;
            activeIndex = -1;
            list.innerHTML = '';

            if (found.length === 0) {
                const empty = document.createElement('li');
                empty.className = 'lp-empty';
                empty.textContent = 'Ничего не найдено — проверьте написание.';
                list.appendChild(empty);
            }

            found.forEach(function (item, index) {
                const option = document.createElement('li');
                option.className = 'lp-option';
                option.id = hidden.name + '_opt_' + index;
                option.setAttribute('role', 'option');

                const name = document.createElement('span');
                name.className = 'lp-name';
                name.textContent = item.name;
                option.appendChild(name);

                if (item.chain !== '') {
                    const chain = document.createElement('span');
                    chain.className = 'lp-chain';
                    chain.textContent = item.chain;
                    option.appendChild(chain);
                }

                // pointerdown опережает blur инпута — клик по строке не
                // успевает закрыть список (критично в браузере WhatsApp).
                option.addEventListener('pointerdown', function (event) {
                    event.preventDefault();
                    pick(item);
                });

                list.appendChild(option);
            });

            list.hidden = false;
            input.setAttribute('aria-expanded', 'true');
        }

        function pick(item) {
            input.value = item.chain === '' ? item.name : item.name + ', ' + item.chain;
            hidden.value = item.id;
            selectedText = input.value;
            toggleClear();
            close();
        }

        function fetchSuggestions(query) {
            const seq = ++requestSeq;

            fetch(searchUrl + '?q=' + encodeURIComponent(query))
                .then(function (response) { return response.json(); })
                .then(function (found) {
                    if (seq !== requestSeq) {
                        return; // устаревший ответ обогнавшего запроса
                    }

                    if (query === '') {
                        topLevel = found;
                    }

                    render(found);
                });
        }

        function showForCurrentText() {
            const query = input.value.trim();

            if (query === '') {
                topLevel !== null ? render(topLevel) : fetchSuggestions('');
            } else if (query.length >= 2) {
                fetchSuggestions(query);
            } else {
                close();
            }
        }

        function moveActive(delta) {
            const options = list.querySelectorAll('.lp-option');

            if (options.length === 0) {
                return;
            }

            if (activeIndex >= 0) {
                options[activeIndex].classList.remove('active');
            }

            activeIndex = (activeIndex + delta + options.length) % options.length;
            options[activeIndex].classList.add('active');
            options[activeIndex].scrollIntoView({ block: 'nearest' });
            input.setAttribute('aria-activedescendant', options[activeIndex].id);
        }

        input.addEventListener('focus', showForCurrentText);
        input.addEventListener('click', function () {
            if (list.hidden) {
                showForCurrentText();
            }
        });

        input.addEventListener('input', function () {
            if (input.value !== selectedText) {
                hidden.value = '';
                selectedText = null;
            }

            toggleClear();
            clearTimeout(timer);
            timer = setTimeout(showForCurrentText, 200);
        });

        input.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                list.hidden ? showForCurrentText() : moveActive(event.key === 'ArrowDown' ? 1 : -1);
            } else if (event.key === 'Enter') {
                // Перехват только при открытом списке: с закрытым Enter
                // по-прежнему отправляет форму фильтров.
                if (!list.hidden) {
                    event.preventDefault();

                    if (activeIndex >= 0 && items[activeIndex]) {
                        pick(items[activeIndex]);
                    } else {
                        close();
                    }
                }
            } else if (event.key === 'Escape' || event.key === 'Tab') {
                close();
            }
        });

        clear.addEventListener('pointerdown', function (event) {
            event.preventDefault();
            input.value = '';
            hidden.value = '';
            selectedText = null;
            toggleClear();
            input.focus();
            showForCurrentText();
        });

        document.addEventListener('pointerdown', function (event) {
            if (!root.contains(event.target)) {
                close();
            }
        });

        toggleClear();
    }
</script>
@endonce

<div class="field">
    <label for="{{ $name }}_search">{{ $label }}</label>
    <div class="location-picker" id="{{ $name }}_picker" data-search-url="{{ route('locations.search') }}">
        <input type="text" class="lp-input" id="{{ $name }}_search"
               @if ($labelName) name="{{ $labelName }}" @endif
               value="{{ $initialText }}" placeholder="{{ $placeholder }}"
               autocomplete="off" role="combobox" aria-expanded="false"
               aria-autocomplete="list" aria-controls="{{ $name }}_listbox">
        <button type="button" class="lp-clear" aria-label="Очистить" hidden>&times;</button>
        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
        <ul class="lp-list" id="{{ $name }}_listbox" role="listbox" hidden></ul>
    </div>
    {{ $slot }}
</div>
<script>initLocationPicker(document.getElementById('{{ $name }}_picker'));</script>
