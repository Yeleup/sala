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
        .back { display: inline-block; margin-bottom: 0.75rem; color: #64748b; font-size: 0.875rem; font-weight: 600; text-decoration: none; }
        .back:hover { color: #1e293b; }
        .field { margin-bottom: 0.875rem; }
        .field label { display: block; font-size: 0.75rem; color: #64748b; font-weight: 600; letter-spacing: 0.03em; text-transform: uppercase; margin-bottom: 0.25rem; }
        .field input, .field select { width: 100%; border: 1px solid #cbd5e1; border-radius: 0.625rem; padding: 0.625rem 0.75rem; font: inherit; background: #fff; transition: border-color 0.15s ease, box-shadow 0.15s ease; }
        .field input:focus, .field select:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgb(37 99 235 / 0.15); }
        .filter-row { display: grid; grid-template-columns: 1fr; gap: 0 1rem; }
        @media (min-width: 40rem) { .filter-row { grid-template-columns: 1fr 1fr 1fr; } }
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
        /* Свайп-галерея страницы объявления: по фото на экран, листается пальцем без скрипта. */
        .gallery { display: flex; gap: 0.5rem; overflow-x: auto; scroll-snap-type: x mandatory; -webkit-overflow-scrolling: touch; margin-bottom: 0.75rem; }
        .gallery img { display: block; width: 100%; flex-shrink: 0; aspect-ratio: 4 / 3; object-fit: cover; border-radius: 0.75rem; border: 1px solid #e2e8f0; scroll-snap-align: center; }
        .gallery-hint { margin: 0.5rem 0 0.75rem; }
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
