@props(['title'])
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    {{-- Standalone styles on purpose: the portal is opened from WhatsApp and must not depend on the Vite build. --}}
    {{-- Единый язык дизайна с каталогом заказчика (components/customer/layout.blade.php): синяя тема, карточки, кнопки. --}}
    <style>
        * { box-sizing: border-box; }
        body { font-family: system-ui, sans-serif; margin: 0; padding: 1.5rem 1rem 3rem; background: #f4f7fc; color: #1e293b; --lp-active-bg: #dbeafe; }
        main { max-width: 32rem; margin: 0 auto; }
        h1 { font-size: 1.25rem; margin: 0 0 0.25rem; }
        a { color: inherit; }
        .page-header { background: linear-gradient(135deg, #1e40af, #3b82f6); border-radius: 1rem; padding: 1.375rem 1.5rem; margin-bottom: 1.25rem; color: #fff; box-shadow: 0 10px 25px -12px rgb(30 64 175 / 0.5); }
        .page-header h1 { font-size: 1.375rem; letter-spacing: -0.01em; }
        .page-header p { margin: 0; color: #dbeafe; font-size: 0.875rem; }
        .page-header .meta h1 { margin: 0; }
        .page-header .meta + p { margin-top: 0.25rem; }
        .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 1rem; padding: 1.25rem; margin-bottom: 1rem; box-shadow: 0 1px 2px rgb(15 23 42 / 0.04); }
        .muted { color: #64748b; font-size: 0.875rem; }
        .meta { display: flex; justify-content: space-between; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
        .badge { display: inline-block; white-space: nowrap; font-size: 0.75rem; font-weight: 600; padding: 0.125rem 0.625rem; border-radius: 9999px; }
        .badge-gray { background: #f1f5f9; color: #334155; }
        .badge-amber { background: #fef3c7; color: #92400e; }
        .badge-green { background: #d1fae5; color: #065f46; }
        .badge-red { background: #fee2e2; color: #991b1b; }
        .flash { background: #d1fae5; color: #065f46; border-radius: 0.625rem; padding: 0.75rem 1rem; margin-bottom: 1rem; font-size: 0.875rem; }
        .reason { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; border-radius: 0.625rem; padding: 0.75rem 1rem; margin: 0.75rem 0 0; font-size: 0.875rem; }
        .btn { display: inline-block; border: 1px solid transparent; border-radius: 0.625rem; padding: 0.625rem 1.125rem; font-size: 0.875rem; font-weight: 600; cursor: pointer; text-decoration: none; font-family: inherit; transition: background-color 0.15s ease, box-shadow 0.15s ease; }
        .btn-primary { background: #2563eb; color: #fff; box-shadow: 0 1px 3px rgb(37 99 235 / 0.4); }
        .btn-primary:hover { background: #1d4ed8; }
        .btn-primary:focus-visible { outline: 2px solid #93c5fd; outline-offset: 2px; }
        .btn-secondary { background: #fff; color: #334155; border-color: #cbd5e1; }
        .btn-secondary:hover { background: #f1f5f9; }
        .btn-danger { background: #fee2e2; color: #991b1b; border-color: #fecaca; }
        .btn-danger:hover { background: #fecaca; }
        .btn-danger:focus-visible { outline: 2px solid #fca5a5; outline-offset: 2px; }
        .actions { margin-top: 1rem; display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center; }
        .back { display: inline-block; margin-bottom: 0.75rem; color: #64748b; font-size: 0.875rem; font-weight: 600; text-decoration: none; }
        .back:hover { color: #1e293b; }
        .field { margin-bottom: 0.875rem; }
        .field label { display: block; font-size: 0.75rem; color: #64748b; font-weight: 600; letter-spacing: 0.03em; text-transform: uppercase; margin-bottom: 0.25rem; }
        .field input, .field select, .field textarea { width: 100%; border: 1px solid #cbd5e1; border-radius: 0.625rem; padding: 0.625rem 0.75rem; font: inherit; background: #fff; transition: border-color 0.15s ease, box-shadow 0.15s ease; }
        /* :not([type="checkbox"]) — чекбоксы «удалить» у фото сохраняют нативный фокус-индикатор. */
        .field input:not([type="checkbox"]):focus, .field select:focus, .field textarea:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgb(37 99 235 / 0.15); }
        .field .error { color: #b91c1c; font-size: 0.8125rem; margin-top: 0.25rem; }
        dt { font-size: 0.75rem; color: #64748b; font-weight: 600; letter-spacing: 0.03em; text-transform: uppercase; margin-top: 0.75rem; }
        dt:first-child { margin-top: 0; }
        dd { margin: 0.125rem 0 0; }
        .photos { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 0.5rem; }
        .photos img { width: 5rem; height: 5rem; object-fit: cover; border-radius: 0.625rem; border: 1px solid #e2e8f0; }
        .field .photo-tile { display: flex; flex-direction: column; align-items: center; gap: 0.25rem; margin: 0; font-size: 0.8125rem; color: #64748b; text-transform: none; }
        .field .photo-tile input { width: auto; }
        .empty-state { text-align: center; padding: 2rem 1.25rem; color: #475569; }
    </style>
</head>
<body>
    <main>
        @if (session('status'))
            <div class="flash">{{ session('status') }}</div>
        @endif

        {{ $slot }}
    </main>
</body>
</html>
