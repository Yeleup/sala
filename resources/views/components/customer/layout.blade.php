@props(['title'])
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    {{-- Standalone styles on purpose: the catalog is opened from WhatsApp and must not depend on the Vite build. --}}
    <style>
        * { box-sizing: border-box; }
        body { font-family: system-ui, sans-serif; margin: 0; padding: 1.5rem 1rem 3rem; background: #f4f7fc; color: #1e293b; --lp-active-bg: #dbeafe; }
        main { max-width: 48rem; margin: 0 auto; }
        h1 { font-size: 1.25rem; margin: 0 0 0.25rem; }
        a { color: inherit; }
        .page-header { background: linear-gradient(135deg, #1e40af, #3b82f6); border-radius: 1rem; padding: 1.375rem 1.5rem; margin-bottom: 1.25rem; color: #fff; box-shadow: 0 10px 25px -12px rgb(30 64 175 / 0.5); }
        .page-header h1 { font-size: 1.375rem; letter-spacing: -0.01em; overflow-wrap: anywhere; }
        .page-header p { margin: 0; color: #dbeafe; font-size: 0.875rem; overflow-wrap: anywhere; }
        /* Отступ и радиус вынесены в переменные: галерея объявления выходит в край карточки и обязана их знать. */
        .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 1rem; padding: 1.25rem; margin-bottom: 1rem; box-shadow: 0 1px 2px rgb(15 23 42 / 0.04); --card-pad: 1.25rem; --card-radius: 1rem; }
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
        .back { display: inline-block; margin-bottom: 0.75rem; color: #64748b; font-size: 0.875rem; font-weight: 600; text-decoration: none; }
        .back:hover { color: #1e293b; }
        .field { margin-bottom: 0.875rem; }
        .field label { display: block; font-size: 0.75rem; color: #64748b; font-weight: 600; letter-spacing: 0.03em; text-transform: uppercase; margin-bottom: 0.25rem; }
        .field input, .field select { width: 100%; border: 1px solid #cbd5e1; border-radius: 0.625rem; padding: 0.625rem 0.75rem; font: inherit; background: #fff; transition: border-color 0.15s ease, box-shadow 0.15s ease; }
        .field input:focus, .field select:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgb(37 99 235 / 0.15); }
        .filter-row { display: grid; grid-template-columns: 1fr; gap: 0 1rem; }
        /* Sizes itself to the fields actually shown: a kind branch has no «Категория». */
        @media (min-width: 40rem) { .filter-row { grid-auto-flow: column; grid-auto-columns: 1fr; } }
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
        /* Мастер и водитель — люди: имя открывает карточку строкой над названием. */
        .listing-person { margin: 0 0 0.125rem; font-size: 0.8125rem; font-weight: 700; color: #475569; overflow-wrap: anywhere; }
        /* Бейдж проверенного документа водителя — зелёная пилюля в теле карточки. */
        .card-badge { display: inline-block; white-space: nowrap; background: #d1fae5; color: #065f46; font-size: 0.75rem; font-weight: 600; padding: 0.125rem 0.625rem; border-radius: 9999px; margin: 0.25rem 0; }
        /* Слайдер фото на странице объявления: кадр во всю ширину карточки, листается
           пальцем без скрипта. Высота сцены фиксирована, а не задана aspect-ratio:
           размеры фотографий нигде не хранятся, и без известной заранее высоты кнопка
           «Выбрать» прыгала бы под пальцем по мере загрузки. Двойное объявление height —
           для движков без clamp(). Фото вписывается целиком поверх размытой копии себя:
           снятые на телефон вертикальные кадры не обрезаются и не оставляют пустых полей. */
        .gallery { --gallery-peek: 1.5rem; margin: 0 0 0.875rem; }
        .gallery-frame { position: relative; overflow: hidden; background: #eef2f7; margin: calc(var(--card-pad) * -1) calc(var(--card-pad) * -1) 0; border-radius: calc(var(--card-radius) - 1px) calc(var(--card-radius) - 1px) 0 0; box-shadow: inset 0 -1px 0 #e2e8f0; }
        .gallery-stage { display: flex; gap: 0.375rem; overflow-x: auto; overflow-y: hidden; height: 17rem; height: clamp(15rem, 76vw, 22rem); scroll-snap-type: x mandatory; overscroll-behavior-x: contain; -webkit-overflow-scrolling: touch; }
        /* Кольцо фокуса внутрь: снаружи его срежет край карточки. */
        .gallery-stage:focus-visible { outline: 2px solid #93c5fd; outline-offset: -2px; }
        /* Край следующего кадра остаётся видимым — это и есть бесскриптовый сигнал «листается». */
        .gallery-slide { position: relative; overflow: hidden; background: #eef2f7; flex: 0 0 calc(100% - var(--gallery-peek)); scroll-snap-align: start; scroll-snap-stop: always; }
        .gallery-slide:last-child { margin-right: var(--gallery-peek); }
        .gallery--single .gallery-slide { flex-basis: 100%; margin-right: 0; }
        .gallery-slide::before { content: ''; position: absolute; top: -10%; right: -10%; bottom: -10%; left: -10%; background-image: var(--slide-photo); background-size: cover; background-position: center; filter: blur(22px); transform: scale(1.1); opacity: 0.5; pointer-events: none; }
        .gallery-slide img { position: relative; z-index: 1; display: block; width: 100%; height: 100%; object-fit: contain; }
        /* Тот же угол и тон, что у пометки «N фото» на миниатюре каталога, откуда пришёл заказчик. */
        .gallery-count { position: absolute; left: 0.625rem; bottom: 0.625rem; background: rgb(15 23 42 / 0.72); color: #fff; font-size: 0.75rem; font-weight: 600; font-variant-numeric: tabular-nums; padding: 0.1875rem 0.5rem; border-radius: 9999px; pointer-events: none; }
        /* Точки, полоса прогресса и стрелки включает только скрипт — без него мёртвых кнопок не остаётся. */
        .gallery-dots, .gallery-rail { display: none; justify-content: center; }
        .gallery-dots { padding: 0.625rem 0 0; }
        .gallery-rail { padding: 0.75rem 0 0.125rem; }
        .gallery-dot { appearance: none; -webkit-appearance: none; margin: 0; padding: 0; border: 0; background: none; width: 2rem; height: 1.75rem; display: flex; align-items: center; justify-content: center; cursor: pointer; }
        .gallery-dot::before { content: ''; display: block; width: 0.375rem; height: 0.375rem; border-radius: 9999px; background: #cbd5e1; transition: width 0.15s ease, background-color 0.15s ease; }
        .gallery-dot.is-active::before { width: 1.125rem; background: #2563eb; }
        .gallery-dot:focus-visible { outline: 2px solid #93c5fd; outline-offset: 2px; border-radius: 0.375rem; }
        .gallery-rail-track { display: block; width: 4rem; height: 3px; border-radius: 9999px; background: #e2e8f0; overflow: hidden; }
        .gallery-rail-fill { display: block; height: 100%; width: 0; border-radius: 9999px; background: #2563eb; transition: width 0.18s ease; }
        .gallery-hint { margin: 0.625rem 0 0; text-align: center; }
        .gallery-arrow { display: none; }
        .gallery.is-enhanced .gallery-stage { scroll-behavior: smooth; scrollbar-width: none; }
        .gallery.is-enhanced .gallery-stage::-webkit-scrollbar { display: none; }
        .gallery.is-enhanced .gallery-dots, .gallery.is-enhanced .gallery-rail { display: flex; }
        .gallery.is-enhanced .gallery-hint { display: none; }
        @media (min-width: 40rem) {
            .gallery-frame { margin: 0 auto; max-width: 32rem; border: 1px solid #e2e8f0; border-radius: 0.875rem; box-shadow: none; }
            .gallery-stage { height: 24rem; }
        }
        @media (min-width: 40rem) and (hover: hover) {
            .gallery.is-enhanced .gallery-arrow { display: grid; place-items: center; position: absolute; top: 50%; transform: translateY(-50%); width: 2.25rem; height: 2.25rem; padding: 0; border-radius: 9999px; background: rgb(255 255 255 / 0.92); border: 1px solid #e2e8f0; box-shadow: 0 1px 2px rgb(15 23 42 / 0.04); cursor: pointer; transition: background-color 0.15s ease, border-color 0.15s ease, opacity 0.15s ease; }
            .gallery.is-enhanced .gallery-arrow-prev { left: 0.625rem; }
            .gallery.is-enhanced .gallery-arrow-next { right: 0.625rem; }
            .gallery.is-enhanced .gallery-arrow:hover { background: #fff; border-color: #bfdbfe; }
            .gallery.is-enhanced .gallery-arrow:focus-visible { outline: 2px solid #93c5fd; outline-offset: 2px; }
            .gallery.is-enhanced .gallery-arrow[disabled] { opacity: 0; pointer-events: none; }
        }
        @media (prefers-reduced-motion: reduce) {
            .gallery.is-enhanced .gallery-stage { scroll-behavior: auto; }
            .gallery-dot::before, .gallery-rail-fill, .gallery-arrow { transition-duration: 0.01ms; }
        }
        .prewrap { white-space: pre-line; }
        .empty-state { text-align: center; padding: 2rem 1.25rem; color: #475569; }
        .pager { display: flex; justify-content: space-between; align-items: center; gap: 0.5rem; margin: 1.5rem 0 0; }
        .pager-link { font-size: 0.875rem; font-weight: 600; text-decoration: none; color: #1d4ed8; background: #dbeafe; padding: 0.5rem 0.875rem; border-radius: 0.625rem; transition: background-color 0.15s ease; }
        .pager-link:hover { background: #bfdbfe; }
        .pager-link.disabled { color: #94a3b8; background: #e9edf3; }
    </style>
</head>
<body>
    <main>
        @if (session('status'))
            <div class="flash">{{ session('status') }}</div>
        @endif

        @if (session('error'))
            <div class="flash flash-error">{{ session('error') }}</div>
        @endif

        {{ $slot }}
    </main>
</body>
</html>
