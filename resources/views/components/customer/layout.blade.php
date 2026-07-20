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
        body { font-family: system-ui, sans-serif; margin: 0; padding: 1.5rem 1rem 3rem; background: #f9fafb; color: #111827; }
        main { max-width: 48rem; margin: 0 auto; }
        h1 { font-size: 1.25rem; margin: 0 0 0.25rem; }
        a { color: inherit; }
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
        @media (min-width: 40rem) { .filter-row { grid-template-columns: 1fr 1fr 1fr; } }
        .listing-card { display: flex; gap: 1rem; }
        .listing-card .thumb { width: 6rem; height: 6rem; object-fit: cover; border-radius: 0.5rem; border: 1px solid #e5e7eb; flex-shrink: 0; }
        .listing-card .listing-body { flex: 1; min-width: 0; }
        .listing-title { font-size: 1rem; margin: 0 0 0.25rem; overflow-wrap: anywhere; }
        .listing-line { margin: 0.25rem 0; font-size: 0.875rem; overflow-wrap: anywhere; }
        .pager { display: flex; justify-content: space-between; align-items: center; gap: 0.5rem; margin: 1.5rem 0 0; }
        .pager-link { font-size: 0.875rem; font-weight: 600; text-decoration: none; }
        .pager-link.disabled { color: #d1d5db; }
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
