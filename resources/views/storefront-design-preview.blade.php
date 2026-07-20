{{--
    Дизайн-превью витрины (storefront) для ревью без запуска реальных
    страниц. Правило проекта: любое изменение витринного UI отражается
    здесь визуально близко к продакшену.

    Сейчас витрина — веб-каталог заказчика (resources/views/customer/
    catalog.blade.php + components/customer/layout.blade.php): состояния
    desktop и mobile, панель фильтров, карточки с «Выбрать» и бейджем
    отправленной заявки, баннеры успеха/дубликата/ошибки, пустая выдача.
--}}
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Дизайн-превью витрины</title>
    <style>
        /* Обвязка самого превью */
        * { box-sizing: border-box; }
        body { font-family: system-ui, sans-serif; margin: 0; padding: 2rem 1.5rem 4rem; background: #e5e7eb; color: #111827; }
        .preview-section { max-width: 64rem; margin: 0 auto 2.5rem; }
        .preview-section > h2 { font-size: 1rem; margin: 0 0 0.75rem; color: #374151; }
        .viewport { background: #f9fafb; border: 1px solid #d1d5db; border-radius: 1rem; overflow: hidden; box-shadow: 0 1px 3px rgb(0 0 0 / 0.1); }
        .viewport-desktop { padding: 1.5rem 1rem 3rem; }
        .viewport-mobile { width: 375px; margin: 0; padding: 1.5rem 1rem 3rem; }
        .viewport main { max-width: 48rem; margin: 0 auto; }
        .viewport-mobile main { max-width: 100%; }

        /* Копия стилей components/customer/layout.blade.php */
        .viewport h1 { font-size: 1.25rem; margin: 0 0 0.25rem; }
        .viewport a { color: inherit; }
        .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 0.75rem; padding: 1.25rem; margin-bottom: 1rem; }
        .muted { color: #6b7280; font-size: 0.875rem; }
        .badge { display: inline-block; white-space: nowrap; font-size: 0.75rem; font-weight: 600; padding: 0.125rem 0.625rem; border-radius: 9999px; }
        .badge-green { background: #d1fae5; color: #065f46; }
        .flash { background: #d1fae5; color: #065f46; border-radius: 0.5rem; padding: 0.75rem 1rem; margin-bottom: 1rem; font-size: 0.875rem; }
        .flash-error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
        .btn { display: inline-block; border: 0; border-radius: 0.5rem; padding: 0.625rem 1rem; font-size: 0.875rem; font-weight: 600; cursor: pointer; text-decoration: none; font-family: inherit; }
        .btn-primary { background: #111827; color: #fff; }
        .btn-secondary { background: #f3f4f6; color: #374151; }
        .actions { margin-top: 1rem; display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center; }
        .field { margin-bottom: 0.875rem; }
        .field label { display: block; font-size: 0.75rem; color: #6b7280; text-transform: uppercase; margin-bottom: 0.25rem; }
        .field input, .field select { width: 100%; border: 1px solid #d1d5db; border-radius: 0.5rem; padding: 0.625rem 0.75rem; font: inherit; background: #fff; }
        .filter-row { display: grid; grid-template-columns: 1fr; gap: 0 1rem; }
        .viewport-desktop .filter-row { grid-template-columns: 1fr 1fr 1fr; }
        .listing-card { display: flex; gap: 1rem; }
        .listing-card .thumb { width: 6rem; height: 6rem; object-fit: cover; border-radius: 0.5rem; border: 1px solid #e5e7eb; flex-shrink: 0; }
        .listing-card .listing-body { flex: 1; min-width: 0; }
        .listing-title { font-size: 1rem; margin: 0 0 0.25rem; overflow-wrap: anywhere; }
        .listing-line { margin: 0.25rem 0; font-size: 0.875rem; overflow-wrap: anywhere; }
        .pager { display: flex; justify-content: space-between; align-items: center; gap: 0.5rem; margin: 1.5rem 0 0; }
        .pager-link { font-size: 0.875rem; font-weight: 600; text-decoration: none; }
        .pager-link.disabled { color: #d1d5db; }

        /* Фото-плейсхолдер только для превью (в продакшене — <img> из хранилища) */
        .thumb-placeholder { width: 6rem; height: 6rem; border-radius: 0.5rem; border: 1px solid #e5e7eb; background: repeating-linear-gradient(45deg, #f3f4f6, #f3f4f6 8px, #e5e7eb 8px, #e5e7eb 16px); flex-shrink: 0; display: flex; align-items: center; justify-content: center; color: #9ca3af; font-size: 0.6875rem; }

        /* Копия стилей components/location-picker.blade.php */
        .location-picker { position: relative; }
        .location-picker .lp-input { padding-right: 2.25rem; }
        .location-picker .lp-clear { position: absolute; top: 0; right: 0; border: 0; background: none; font-size: 1.25rem; line-height: 1; color: #6b7280; cursor: pointer; padding: 0.625rem 0.75rem; }
        .location-picker .lp-list { position: absolute; top: calc(100% + 0.25rem); left: 0; right: 0; z-index: 30; margin: 0; padding: 0.25rem; list-style: none; background: #fff; border: 1px solid #d1d5db; border-radius: 0.5rem; box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1); max-height: 16rem; overflow-y: auto; }
        .location-picker .lp-option { padding: 0.5rem 0.625rem; border-radius: 0.375rem; cursor: pointer; }
        .location-picker .lp-option.active { background: #f3f4f6; }
        .location-picker .lp-name { display: block; font-weight: 600; font-size: 0.875rem; }
        .location-picker .lp-chain { display: block; color: #6b7280; font-size: 0.8125rem; }
        /* В превью открытые списки показываются в потоке, чтобы не перекрывать соседние секции */
        .preview-open-list .lp-list { position: static; margin-top: 0.25rem; }
    </style>
</head>
<body>

<div class="preview-section">
    <h2>Каталог заказчика — desktop (после «Выбрать»: баннер успеха, у карточки бейдж)</h2>
    <div class="viewport viewport-desktop">
        <main>
            <div class="flash">Заявка отправлена поставщику. Его ответ придёт вам в WhatsApp.</div>

            <h1>Каталог объявлений</h1>
            <p class="muted" style="margin: 0 0 1rem;">Спецтехника и услуги — все опубликованные объявления.</p>

            <div class="card">
                <div class="field">
                    <label>Поиск</label>
                    <input value="кран 25 тонн" placeholder="Что ищете? Например: кран 25 тонн">
                </div>
                <div class="filter-row">
                    <div class="field">
                        <label>Категория</label>
                        <select><option>— все категории —</option><option selected>Автокран</option><option>Экскаватор</option></select>
                    </div>
                    <div class="field preview-open-list">
                        <label>Место</label>
                        <div class="location-picker">
                            <input class="lp-input" value="кара" placeholder="Город, район или село">
                            <button type="button" class="lp-clear">&times;</button>
                            <ul class="lp-list">
                                <li class="lp-option active"><span class="lp-name">Каратауский район</span><span class="lp-chain">г.Шымкент</span></li>
                                <li class="lp-option"><span class="lp-name">Карагандинская область</span></li>
                                <li class="lp-option"><span class="lp-name">с.Карабулак</span><span class="lp-chain">Сарыагашский район, Туркестанская область</span></li>
                            </ul>
                        </div>
                    </div>
                    <div class="field">
                        <label>Сортировка</label>
                        <select><option selected>По соответствию запросу</option><option>Сначала новые</option><option>Сначала старые</option></select>
                    </div>
                </div>
                <div class="actions" style="margin-top: 0.25rem;">
                    <button class="btn btn-primary">Показать</button>
                    <a class="btn btn-secondary" href="#">Сбросить</a>
                </div>
            </div>

            <p class="muted" style="margin: 0 0 1rem;">Найдено объявлений: 27</p>

            <div class="card listing-card">
                <div class="thumb-placeholder">фото</div>
                <div class="listing-body">
                    <h2 class="listing-title">Аренда автокрана 25 т</h2>
                    <p class="listing-line muted">Техника · Автокран · XCMG</p>
                    <p class="listing-line">Кран 25 тонн со стрелой 40 м, работаем по городу и области, опытный машинист.</p>
                    <p class="listing-line">г.Шымкент, центр · 20000 тг/ч</p>
                    <p class="listing-line muted">Поставщик: Асхат</p>
                    <div class="actions"><button class="btn btn-primary">Выбрать</button></div>
                </div>
            </div>

            <div class="card listing-card">
                <div class="listing-body">
                    <h2 class="listing-title">Кран-манипулятор 5 т</h2>
                    <p class="listing-line muted">Техника · Автокран</p>
                    <p class="listing-line">Борт 6 метров, перевозка и разгрузка в одной машине.</p>
                    <p class="listing-line">Каратауский район, г.Шымкент · 15000 тг/ч</p>
                    <p class="listing-line muted">Поставщик: Мағжан</p>
                    <div class="actions"><span class="badge badge-green">Заявка отправлена — ждём ответа поставщика</span></div>
                </div>
            </div>

            <nav class="pager">
                <span class="pager-link disabled">&larr; Назад</span>
                <span class="muted">Страница 1 из 2</span>
                <a class="pager-link" href="#">Вперёд &rarr;</a>
            </nav>
        </main>
    </div>
</div>

<div class="preview-section">
    <h2>Каталог заказчика — mobile 375px (фильтры в одну колонку)</h2>
    <div class="viewport viewport-mobile">
        <main>
            <h1>Каталог объявлений</h1>
            <p class="muted" style="margin: 0 0 1rem;">Спецтехника и услуги — все опубликованные объявления.</p>

            <div class="card">
                <div class="field">
                    <label>Поиск</label>
                    <input placeholder="Что ищете? Например: кран 25 тонн">
                </div>
                <div class="filter-row">
                    <div class="field">
                        <label>Категория</label>
                        <select><option>— все категории —</option></select>
                    </div>
                    <div class="field preview-open-list">
                        <label>Место</label>
                        {{-- Пустое поле в фокусе: сразу список областей и городов респ. значения --}}
                        <div class="location-picker">
                            <input class="lp-input" placeholder="Город, район или село">
                            <ul class="lp-list">
                                <li class="lp-option"><span class="lp-name">г.Алматы</span></li>
                                <li class="lp-option"><span class="lp-name">г.Астана</span></li>
                                <li class="lp-option"><span class="lp-name">г.Шымкент</span></li>
                                <li class="lp-option"><span class="lp-name">Абайская область</span></li>
                                <li class="lp-option"><span class="lp-name">Акмолинская область</span></li>
                            </ul>
                        </div>
                    </div>
                    <div class="field">
                        <label>Сортировка</label>
                        <select><option>Сначала новые</option><option>Сначала старые</option></select>
                    </div>
                </div>
                <div class="actions" style="margin-top: 0.25rem;">
                    <button class="btn btn-primary">Показать</button>
                    <a class="btn btn-secondary" href="#">Сбросить</a>
                </div>
            </div>

            <p class="muted" style="margin: 0 0 1rem;">Найдено объявлений: 27</p>

            <div class="card listing-card">
                <div class="thumb-placeholder">фото</div>
                <div class="listing-body">
                    <h2 class="listing-title">Аренда автокрана 25 т</h2>
                    <p class="listing-line muted">Техника · Автокран · XCMG</p>
                    <p class="listing-line">г.Шымкент, центр · 20000 тг/ч</p>
                    <p class="listing-line muted">Поставщик: Асхат</p>
                    <div class="actions"><button class="btn btn-primary">Выбрать</button></div>
                </div>
            </div>

            <nav class="pager">
                <span class="pager-link disabled">&larr; Назад</span>
                <span class="muted">Страница 1 из 2</span>
                <a class="pager-link" href="#">Вперёд &rarr;</a>
            </nav>
        </main>
    </div>
</div>

<div class="preview-section">
    <h2>Баннеры состояний «Выбрать» (дубликат заявки и ушедшее из публикации объявление)</h2>
    <div class="viewport viewport-desktop">
        <main>
            <div class="flash">Вы уже отправляли заявку по этому объявлению — ждём ответа поставщика.</div>
            <div class="flash flash-error">Это объявление уже не публикуется. Выберите, пожалуйста, другой вариант.</div>
        </main>
    </div>
</div>

<div class="preview-section">
    <h2>Пустая выдача</h2>
    <div class="viewport viewport-desktop">
        <main>
            <p class="muted" style="margin: 0 0 1rem;">Найдено объявлений: 0</p>
            <div class="card">
                <p style="margin: 0;">Ничего не нашлось. Измените запрос или сбросьте фильтры.</p>
            </div>
        </main>
    </div>
</div>

</body>
</html>
