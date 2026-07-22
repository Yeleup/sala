{{--
    Дизайн-превью витрины (storefront) для ревью без запуска реальных
    страниц. Правило проекта: любое изменение витринного UI отражается
    здесь визуально близко к продакшену.

    Витрина — веб-каталог заказчика (resources/views/customer/
    catalog.blade.php + components/customer/layout.blade.php): состояния
    desktop и mobile, панель фильтров, карточки с «Выбрать» и бейджем
    отправленной заявки, баннеры успеха/дубликата/ошибки, пустая выдача.
    Тема — синяя (градиентная шапка, синие кнопки и акценты).

    Портал поставщика (resources/views/supplier/* +
    components/supplier/layout.blade.php) использует тот же язык дизайна:
    «Мои объявления» с карточкой имени и статусами, редактирование
    отклонённого объявления (причина, форма, фото), просмотр
    опубликованного (шапка со статусом, данные списком, снятие с публикации).
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
        .viewport { background: #f4f7fc; border: 1px solid #d1d5db; border-radius: 1rem; overflow: hidden; box-shadow: 0 1px 3px rgb(0 0 0 / 0.1); color: #1e293b; --lp-active-bg: #dbeafe; }
        .viewport-desktop { padding: 1.5rem 1rem 3rem; }
        .viewport-mobile { width: 375px; margin: 0; padding: 1.5rem 1rem 3rem; }
        .viewport main { max-width: 48rem; margin: 0 auto; }
        .viewport-mobile main { max-width: 100%; }

        /* Копия стилей components/customer/layout.blade.php */
        .viewport h1 { font-size: 1.25rem; margin: 0 0 0.25rem; }
        .viewport a { color: inherit; }
        .page-header { background: linear-gradient(135deg, #1e40af, #3b82f6); border-radius: 1rem; padding: 1.375rem 1.5rem; margin-bottom: 1.25rem; color: #fff; box-shadow: 0 10px 25px -12px rgb(30 64 175 / 0.5); }
        .page-header h1 { font-size: 1.375rem; letter-spacing: -0.01em; overflow-wrap: anywhere; }
        .page-header p { margin: 0; color: #dbeafe; font-size: 0.875rem; overflow-wrap: anywhere; }
        .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 1rem; padding: 1.25rem; margin-bottom: 1rem; box-shadow: 0 1px 2px rgb(15 23 42 / 0.04); }
        .muted { color: #64748b; font-size: 0.875rem; }
        .result-count { margin: 0 0 1rem; padding-left: 0.25rem; }
        .badge { display: inline-block; white-space: nowrap; font-size: 0.75rem; font-weight: 600; padding: 0.125rem 0.625rem; border-radius: 9999px; }
        .badge-green { background: #d1fae5; color: #065f46; }
        .flash { background: #d1fae5; color: #065f46; border-radius: 0.625rem; padding: 0.75rem 1rem; margin-bottom: 1rem; font-size: 0.875rem; }
        .flash-error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
        .btn { display: inline-block; border: 1px solid transparent; border-radius: 0.625rem; padding: 0.625rem 1.125rem; font-size: 0.875rem; font-weight: 600; cursor: pointer; text-decoration: none; font-family: inherit; transition: background-color 0.15s ease, box-shadow 0.15s ease; }
        .btn-primary { background: #2563eb; color: #fff; box-shadow: 0 1px 3px rgb(37 99 235 / 0.4); }
        .btn-primary:hover { background: #1d4ed8; }
        .btn-primary:focus-visible { outline: 2px solid #93c5fd; outline-offset: 2px; }
        .btn-secondary { background: #fff; color: #334155; border-color: #cbd5e1; }
        .btn-secondary:hover { background: #f1f5f9; }
        .actions { margin-top: 1rem; display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center; }
        .field { margin-bottom: 0.875rem; }
        .field label { display: block; font-size: 0.75rem; color: #64748b; font-weight: 600; letter-spacing: 0.03em; text-transform: uppercase; margin-bottom: 0.25rem; }
        .field input, .field select, .field textarea { width: 100%; border: 1px solid #cbd5e1; border-radius: 0.625rem; padding: 0.625rem 0.75rem; font: inherit; background: #fff; transition: border-color 0.15s ease, box-shadow 0.15s ease; }
        .field input:not([type="checkbox"]):focus, .field select:focus, .field textarea:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgb(37 99 235 / 0.15); }
        .filter-row { display: grid; grid-template-columns: 1fr; gap: 0 1rem; }
        .viewport-desktop .filter-row { grid-template-columns: 1fr 1fr 1fr; }
        .listing-card { display: flex; gap: 1rem; transition: border-color 0.15s ease, box-shadow 0.15s ease; }
        .listing-card:hover { border-color: #bfdbfe; box-shadow: 0 6px 16px -6px rgb(37 99 235 / 0.25); }
        .listing-card .thumb { display: block; width: 6rem; height: 6rem; object-fit: cover; border-radius: 0.625rem; border: 1px solid #e2e8f0; flex-shrink: 0; }
        .listing-card .listing-body { flex: 1; min-width: 0; }
        .thumb-link { position: relative; flex-shrink: 0; align-self: flex-start; }
        .thumb-count { position: absolute; left: 0.25rem; bottom: 0.25rem; background: rgb(15 23 42 / 0.7); color: #fff; font-size: 0.6875rem; font-weight: 600; padding: 0.125rem 0.375rem; border-radius: 0.375rem; pointer-events: none; }
        .listing-title { font-size: 1.0625rem; color: #0f172a; margin: 0 0 0.25rem; overflow-wrap: anywhere; }
        .title-link { text-decoration: none; }
        .title-link:hover { color: #1d4ed8; text-decoration: underline; }
        .listing-line { margin: 0.25rem 0; font-size: 0.875rem; overflow-wrap: anywhere; }
        .listing-price { color: #1d4ed8; font-weight: 700; font-size: 0.9375rem; }
        .gallery { display: flex; gap: 0.5rem; overflow-x: auto; scroll-snap-type: x mandatory; margin-bottom: 0.75rem; }
        .gallery img { display: block; width: 100%; flex-shrink: 0; aspect-ratio: 4 / 3; object-fit: cover; border-radius: 0.75rem; border: 1px solid #e2e8f0; scroll-snap-align: center; }
        .gallery-hint { margin: 0.5rem 0 0.75rem; }
        .prewrap { white-space: pre-line; }
        .empty-state { text-align: center; padding: 2rem 1.25rem; color: #475569; }
        .pager { display: flex; justify-content: space-between; align-items: center; gap: 0.5rem; margin: 1.5rem 0 0; }
        .pager-link { font-size: 0.875rem; font-weight: 600; text-decoration: none; color: #1d4ed8; background: #dbeafe; padding: 0.5rem 0.875rem; border-radius: 0.625rem; transition: background-color 0.15s ease; }
        .pager-link:hover { background: #bfdbfe; }
        .pager-link.disabled { color: #94a3b8; background: #e9edf3; }

        /* Копия стилей components/supplier/layout.blade.php (портал поставщика) */
        .viewport-supplier main { max-width: 32rem; }
        .page-header .meta h1 { margin: 0; }
        .page-header .meta + p { margin-top: 0.25rem; }
        .meta { display: flex; justify-content: space-between; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
        .badge-gray { background: #f1f5f9; color: #334155; }
        .badge-amber { background: #fef3c7; color: #92400e; }
        .badge-red { background: #fee2e2; color: #991b1b; }
        .reason { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; border-radius: 0.625rem; padding: 0.75rem 1rem; margin: 0.75rem 0 0; font-size: 0.875rem; }
        .btn-danger { background: #fee2e2; color: #991b1b; border-color: #fecaca; }
        .btn-danger:hover { background: #fecaca; }
        .btn-danger:focus-visible { outline: 2px solid #fca5a5; outline-offset: 2px; }
        .back { display: inline-block; margin-bottom: 0.75rem; color: #64748b; font-size: 0.875rem; font-weight: 600; text-decoration: none; }
        .back:hover { color: #1e293b; }
        .field .error { color: #b91c1c; font-size: 0.8125rem; margin-top: 0.25rem; }
        dt { font-size: 0.75rem; color: #64748b; font-weight: 600; letter-spacing: 0.03em; text-transform: uppercase; margin-top: 0.75rem; }
        dt:first-child { margin-top: 0; }
        dd { margin: 0.125rem 0 0; }
        .photos { display: flex; flex-wrap: wrap; gap: 0.625rem; margin-top: 0.5rem; }
        .photos img { width: 6rem; height: 6rem; object-fit: cover; border-radius: 0.625rem; border: 1px solid #e2e8f0; }
        .field .photo-tile { display: flex; flex-direction: column; align-items: center; gap: 0.375rem; margin: 0; font-size: 0.8125rem; font-weight: 400; letter-spacing: normal; color: #64748b; text-transform: none; cursor: pointer; }
        .field .photo-tile input { width: auto; accent-color: #dc2626; }
        .photo-tile img { transition: opacity 0.15s ease, border-color 0.15s ease; }
        .photo-remove { display: inline-flex; align-items: center; gap: 0.25rem; }
        .field .photo-tile:has(input:checked) img, .field .photo-tile:has(input:checked) .photo-placeholder { opacity: 0.4; border-color: #fca5a5; }
        .field .photo-tile:has(input:checked) .photo-remove { color: #b91c1c; font-weight: 600; }
        .upload-zone { position: relative; display: flex; flex-direction: column; align-items: center; gap: 0.25rem; padding: 1.375rem 1rem; border: 2px dashed #cbd5e1; border-radius: 0.625rem; background: #f8fafc; text-align: center; transition: border-color 0.15s ease, background-color 0.15s ease; }
        .upload-zone:hover { border-color: #93c5fd; background: #eff6ff; }
        .upload-zone:focus-within { border-color: #2563eb; box-shadow: 0 0 0 3px rgb(37 99 235 / 0.15); }
        .upload-zone input[type="file"] { position: absolute; inset: 0; width: 100%; height: 100%; opacity: 0; border: 0; padding: 0; cursor: pointer; }
        .upload-icon { width: 2.25rem; height: 2.25rem; border-radius: 9999px; background: #dbeafe; color: #1d4ed8; font-size: 1.375rem; line-height: 2.25rem; font-weight: 600; }
        .upload-actions { display: flex; flex-wrap: wrap; justify-content: center; gap: 0.5rem; margin-top: 0.375rem; }
        .upload-actions .upload-choose { pointer-events: none; }
        .field .upload-camera { position: relative; z-index: 1; margin: 0; font-size: 0.875rem; font-weight: 600; letter-spacing: normal; text-transform: none; color: #334155; }
        .upload-camera:focus-within { outline: 2px solid #93c5fd; outline-offset: 2px; }
        .upload-hint { color: #64748b; font-size: 0.8125rem; }
        .upload-count { color: #1e293b; font-size: 0.875rem; font-weight: 600; margin-top: 0.25rem; }
        .upload-clear { position: relative; z-index: 1; border: 0; background: none; padding: 0; color: #64748b; font: inherit; font-size: 0.8125rem; text-decoration: underline; cursor: pointer; }
        .upload-clear:hover { color: #1e293b; }

        /* Фото-плейсхолдеры только для превью (в продакшене — <img> из хранилища) */
        .thumb-placeholder { width: 6rem; height: 6rem; border-radius: 0.625rem; border: 1px solid #e2e8f0; background: repeating-linear-gradient(45deg, #f3f4f6, #f3f4f6 8px, #e5e7eb 8px, #e5e7eb 16px); flex-shrink: 0; display: flex; align-items: center; justify-content: center; color: #9ca3af; font-size: 0.6875rem; }
        .photo-placeholder { width: 6rem; height: 6rem; border-radius: 0.625rem; border: 1px solid #e2e8f0; background: repeating-linear-gradient(45deg, #f3f4f6, #f3f4f6 8px, #e5e7eb 8px, #e5e7eb 16px); display: flex; align-items: center; justify-content: center; color: #9ca3af; font-size: 0.6875rem; transition: opacity 0.15s ease, border-color 0.15s ease; }

        /* Копия стилей components/location-picker.blade.php */
        .location-picker { position: relative; }
        .location-picker .lp-input { padding-right: 2.25rem; }
        .location-picker .lp-clear { position: absolute; top: 0; right: 0; border: 0; background: none; font-size: 1.25rem; line-height: 1; color: #6b7280; cursor: pointer; padding: 0.625rem 0.75rem; }
        .location-picker .lp-list { position: absolute; top: calc(100% + 0.25rem); left: 0; right: 0; z-index: 30; margin: 0; padding: 0.25rem; list-style: none; background: #fff; border: 1px solid #d1d5db; border-radius: 0.5rem; box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1); max-height: 16rem; overflow-y: auto; }
        .location-picker .lp-option { padding: 0.5rem 0.625rem; border-radius: 0.375rem; cursor: pointer; }
        .location-picker .lp-option.active, .location-picker .lp-option:hover { background: var(--lp-active-bg, #f3f4f6); }
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

            <header class="page-header">
                <h1>Каталог объявлений</h1>
                <p>Спецтехника и услуги — все опубликованные объявления.</p>
            </header>

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

            <p class="muted result-count">Найдено объявлений: 27</p>

            <div class="card listing-card">
                <a class="thumb-link" href="#"><div class="thumb-placeholder">фото</div><span class="thumb-count">4 фото</span></a>
                <div class="listing-body">
                    <h2 class="listing-title"><a class="title-link" href="#">Аренда автокрана 25 т</a></h2>
                    <p class="listing-line muted">Техника · Автокран · XCMG</p>
                    <p class="listing-line">Кран 25 тонн со стрелой 40 м, работаем по городу и области, опытный машинист.</p>
                    <p class="listing-line muted">г.Шымкент, центр</p>
                    <p class="listing-line listing-price">20000 тг/ч</p>
                    <p class="listing-line muted">Поставщик: Асхат</p>
                    <div class="actions">
                        <button class="btn btn-primary">Выбрать</button>
                        <a class="btn btn-secondary" href="#">Подробнее</a>
                    </div>
                </div>
            </div>

            <div class="card listing-card">
                <div class="listing-body">
                    <h2 class="listing-title"><a class="title-link" href="#">Кран-манипулятор 5 т</a></h2>
                    <p class="listing-line muted">Техника · Автокран</p>
                    <p class="listing-line">Борт 6 метров, перевозка и разгрузка в одной машине.</p>
                    <p class="listing-line muted">Каратауский район, г.Шымкент</p>
                    <p class="listing-line listing-price">15000 тг/ч</p>
                    <p class="listing-line muted">Поставщик: Мағжан</p>
                    <div class="actions">
                        <span class="badge badge-green">Заявка отправлена — ждём ответа поставщика</span>
                        <a class="btn btn-secondary" href="#">Подробнее</a>
                    </div>
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
            <header class="page-header">
                <h1>Каталог объявлений</h1>
                <p>Спецтехника и услуги — все опубликованные объявления.</p>
            </header>

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

            <p class="muted result-count">Найдено объявлений: 27</p>

            <div class="card listing-card">
                <a class="thumb-link" href="#"><div class="thumb-placeholder">фото</div><span class="thumb-count">4 фото</span></a>
                <div class="listing-body">
                    <h2 class="listing-title"><a class="title-link" href="#">Аренда автокрана 25 т</a></h2>
                    <p class="listing-line muted">Техника · Автокран · XCMG</p>
                    <p class="listing-line muted">г.Шымкент, центр</p>
                    <p class="listing-line listing-price">20000 тг/ч</p>
                    <p class="listing-line muted">Поставщик: Асхат</p>
                    <div class="actions">
                        <button class="btn btn-primary">Выбрать</button>
                        <a class="btn btn-secondary" href="#">Подробнее</a>
                    </div>
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
            <p class="muted result-count">Найдено объявлений: 0</p>
            <div class="card empty-state">
                <p style="margin: 0;">Ничего не нашлось. Измените запрос или сбросьте фильтры.</p>
            </div>
        </main>
    </div>
</div>

<div class="preview-section">
    <h2>Каталог заказчика — страница объявления, mobile 375px (свайп-галерея всех фото, полное описание)</h2>
    <div class="viewport viewport-mobile">
        <main>
            <a class="back" href="#">&larr; Назад к каталогу</a>

            <header class="page-header">
                <h1>Аренда автокрана 25 т</h1>
                <p>Техника · Автокран · XCMG</p>
            </header>

            <div class="card">
                {{-- В продакшене фото листаются свайпом: по одному на экран --}}
                <div class="gallery">
                    <div class="thumb-placeholder" style="width: 100%; flex-shrink: 0; aspect-ratio: 4 / 3; height: auto; border-radius: 0.75rem; scroll-snap-align: center;">фото 1</div>
                    <div class="thumb-placeholder" style="width: 100%; flex-shrink: 0; aspect-ratio: 4 / 3; height: auto; border-radius: 0.75rem; scroll-snap-align: center;">фото 2</div>
                    <div class="thumb-placeholder" style="width: 100%; flex-shrink: 0; aspect-ratio: 4 / 3; height: auto; border-radius: 0.75rem; scroll-snap-align: center;">фото 3</div>
                    <div class="thumb-placeholder" style="width: 100%; flex-shrink: 0; aspect-ratio: 4 / 3; height: auto; border-radius: 0.75rem; scroll-snap-align: center;">фото 4</div>
                </div>
                <p class="muted gallery-hint">Фотографий: 4 — листайте вбок.</p>

                <p class="listing-line prewrap">Кран 25 тонн со стрелой 40 м, работаем по городу и области, опытный машинист. Выезд в день обращения, помощь с расчётом нагрузки. Оплата наличными или на счёт.</p>
                <p class="listing-line muted">г.Шымкент, центр</p>
                <p class="listing-line listing-price">20000 тг/ч</p>
                <p class="listing-line muted">Поставщик: Асхат</p>

                <div class="actions"><button class="btn btn-primary">Выбрать</button></div>
            </div>
        </main>
    </div>
</div>

<div class="preview-section">
    <h2>Страница объявления — без фото и с уже отправленной заявкой (бейдж вместо кнопки)</h2>
    <div class="viewport viewport-mobile">
        <main>
            <a class="back" href="#">&larr; Назад к каталогу</a>

            <header class="page-header">
                <h1>Кран-манипулятор 5 т</h1>
                <p>Техника · Автокран</p>
            </header>

            <div class="card">
                <p class="listing-line prewrap">Борт 6 метров, перевозка и разгрузка в одной машине.</p>
                <p class="listing-line muted">Каратауский район, г.Шымкент</p>
                <p class="listing-line listing-price">15000 тг/ч</p>
                <p class="listing-line muted">Поставщик: Мағжан</p>

                <div class="actions"><span class="badge badge-green">Заявка отправлена — ждём ответа поставщика</span></div>
            </div>
        </main>
    </div>
</div>

<div class="preview-section">
    <h2>Портал поставщика — «Мои объявления», mobile 375px (карточка имени, статусы, действия)</h2>
    <div class="viewport viewport-mobile">
        <main>
            <div class="flash">Имя сохранено.</div>

            <header class="page-header">
                <h1>Мои объявления</h1>
                <p>Все ваши объявления и их статусы.</p>
            </header>

            <article class="card">
                <div class="meta">
                    <strong>Ваше имя</strong>
                    <span class="muted">Асхат</span>
                </div>
                <div style="margin-top: 0.75rem;">
                    <div class="field" style="margin-bottom: 0.75rem;">
                        <label>Имя</label>
                        <input value="Асхат" placeholder="Как к вам обращаться">
                        <p class="muted" style="margin: 0.375rem 0 0;">Имя видно во всех ваших объявлениях. Оставьте поле пустым, чтобы использовать имя из WhatsApp.</p>
                    </div>
                    <button class="btn btn-primary">Сохранить имя</button>
                </div>
            </article>

            <article class="card">
                <div class="meta">
                    <strong>Аренда автокрана 25 т</strong>
                    <span class="badge badge-green">Опубликовано</span>
                </div>
                <p class="muted" style="margin: 0.25rem 0 0;">Техника · Автокран XCMG</p>
                <p style="margin: 0.5rem 0 0;">Кран 25 тонн со стрелой 40 м, работаем по городу и области.</p>
                <p class="muted" style="margin: 0.5rem 0 0;">г.Шымкент, центр · 20000 тг/ч</p>
                <p class="muted" style="margin: 0.5rem 0 0;">Опубликовано до 21.08.2026</p>
                <div class="actions">
                    <button class="btn btn-danger">Снять с публикации</button>
                </div>
            </article>

            <article class="card">
                <div class="meta">
                    <strong>Экскаватор Hitachi</strong>
                    <span class="badge badge-amber">На модерации</span>
                </div>
                <p class="muted" style="margin: 0.25rem 0 0;">Техника</p>
                <p class="muted" style="margin: 0.5rem 0 0;">Каратауский район, г.Шымкент · 25000 тг/ч</p>
            </article>

            <article class="card">
                <div class="meta">
                    <strong>Без категории</strong>
                    <span class="badge badge-gray">Черновик</span>
                </div>
                <p class="muted" style="margin: 0.25rem 0 0;">Техника</p>
                <p class="muted" style="margin: 0.5rem 0 0;">Локация и цена не указаны</p>
                <div class="actions">
                    <a class="btn btn-primary" href="#">Редактировать</a>
                </div>
            </article>

            <article class="card">
                <div class="meta">
                    <strong>Услуги манипулятора</strong>
                    <span class="badge badge-red">Отклонено</span>
                </div>
                <p class="muted" style="margin: 0.25rem 0 0;">Услуги</p>
                <p class="muted" style="margin: 0.5rem 0 0;">Локация и цена не указаны</p>
                <p class="reason">Причина отклонения: Не указана цена — добавьте тариф.</p>
                <div class="actions">
                    <a class="btn btn-primary" href="#">Исправить и отправить снова</a>
                </div>
            </article>
        </main>
    </div>
</div>

<div class="preview-section">
    <h2>Портал поставщика — редактирование отклонённого объявления (шапка со статусом, причина, форма)</h2>
    <div class="viewport viewport-desktop viewport-supplier">
        <main>
            <a class="back" href="#">&larr; Мои объявления</a>

            <header class="page-header">
                <div class="meta">
                    <h1>Редактирование объявления</h1>
                    <span class="badge badge-red">Отклонено</span>
                </div>
                <p>Проверьте данные и заполните недостающее — после сохранения объявление уйдёт на проверку модератору.</p>
            </header>

            <div class="card">
                <p class="reason" style="margin: 0 0 1rem;">Причина отклонения: Не указана цена — добавьте тариф.</p>

                <div class="field">
                    <label>Тип</label>
                    <select><option selected>Техника</option><option>Услуги</option></select>
                </div>
                <div class="field">
                    <label>Название</label>
                    <input value="Кран-манипулятор 5 т" placeholder="Например: Аренда автокрана 25 т">
                </div>
                <div class="field">
                    <label>Категория</label>
                    <select><option>— выберите категорию —</option><option selected>Краны-манипуляторы (КМУ)</option></select>
                    <p class="muted" style="margin: 0.25rem 0 0;">Категория должна соответствовать выбранному типу.</p>
                </div>
                <div class="field">
                    <label>Марка (необязательно)</label>
                    <select><option selected>— без марки —</option><option>КАМАЗ</option></select>
                    <p class="muted" style="margin: 0.25rem 0 0;">Производитель техники; у услуг марки нет.</p>
                </div>
                <div class="field">
                    <label>Описание</label>
                    <textarea rows="4" placeholder="Что предлагаете, характеристики, условия">Борт 6 метров, перевозка и разгрузка в одной машине.</textarea>
                </div>
                <div class="field">
                    <label>Локация</label>
                    <div class="location-picker">
                        <input class="lp-input" value="Каратауский район, г.Шымкент" placeholder="Начните вводить: город, район или село">
                        <button type="button" class="lp-clear">&times;</button>
                    </div>
                    <p class="muted" style="margin: 0.25rem 0 0;">Выберите вариант из подсказок.</p>
                </div>
                <div class="field">
                    <label>Уточнение адреса (необязательно)</label>
                    <input placeholder="Например: центр, мкр Нурсат">
                </div>
                <div class="field">
                    <label>Цена / тариф</label>
                    <input value="" placeholder="Например: 10000 тг/ч">
                    <p class="error">Укажите цену или тариф.</p>
                </div>
                <div class="field">
                    <label>Фотографии</label>
                    <div class="photos">
                        <label class="photo-tile">
                            <div class="photo-placeholder">фото</div>
                            <span class="photo-remove"><input type="checkbox"> удалить</span>
                        </label>
                        {{-- Отмеченное к удалению фото: гаснет и краснеет --}}
                        <label class="photo-tile">
                            <div class="photo-placeholder">фото</div>
                            <span class="photo-remove"><input type="checkbox" checked> удалить</span>
                        </label>
                    </div>
                </div>
                <div class="field">
                    <label>Добавить фотографии</label>
                    <div class="upload-zone">
                        <input type="file" multiple>
                        <span class="upload-icon" aria-hidden="true">+</span>
                        <div class="upload-actions">
                            <span class="btn btn-primary upload-choose">Выбрать фото</span>
                            <label class="btn btn-secondary upload-camera">Снять на камеру<input type="file" capture="environment"></label>
                        </div>
                        <span class="upload-hint">или перетащите файлы сюда</span>
                        <span class="upload-count">Выбрано: 3 файла</span>
                        <button type="button" class="upload-clear">очистить выбор</button>
                    </div>
                    <p class="muted" style="margin: 0.25rem 0 0;">До 10 фото на объявление: JPG, PNG или WebP, каждое до 10 МБ.</p>
                    <p class="error">Файл «IMG_0117.jpg» больше 10 МБ и не добавлен — уменьшите его или выберите другой.</p>
                </div>
                <div class="actions">
                    <button class="btn btn-primary">Сохранить и отправить на проверку</button>
                </div>
            </div>
        </main>
    </div>
</div>

<div class="preview-section">
    <h2>Портал поставщика — просмотр опубликованного объявления (данные списком, снятие с публикации)</h2>
    <div class="viewport viewport-desktop viewport-supplier">
        <main>
            <a class="back" href="#">&larr; Мои объявления</a>

            <header class="page-header">
                <div class="meta">
                    <h1>Ваше объявление</h1>
                    <span class="badge badge-green">Опубликовано</span>
                </div>
                <p>Опубликовано до 21.08.2026.</p>
            </header>

            <div class="card">
                <dl style="margin: 0;">
                    <dt>Тип</dt>
                    <dd>Техника</dd>
                    <dt>Название</dt>
                    <dd>Аренда автокрана 25 т</dd>
                    <dt>Категория</dt>
                    <dd>Автокран</dd>
                    <dt>Марка</dt>
                    <dd>XCMG</dd>
                    <dt>Описание</dt>
                    <dd>Кран 25 тонн со стрелой 40 м, работаем по городу и области.</dd>
                    <dt>Локация</dt>
                    <dd>г.Шымкент, центр</dd>
                    <dt>Цена / тариф</dt>
                    <dd>20000 тг/ч</dd>
                </dl>

                <div class="actions">
                    <button class="btn btn-danger">Снять с публикации</button>
                </div>

                <dl style="margin: 1rem 0 0;"><dt>Фотографии</dt></dl>
                <div class="photos">
                    <div class="photo-placeholder">фото</div>
                    <div class="photo-placeholder">фото</div>
                </div>
            </div>
        </main>
    </div>
</div>

</body>
</html>
