@props(['title'])
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    {{-- Standalone styles on purpose: the portal is opened from WhatsApp and must not depend on the Vite build. --}}
    <style>
        * { box-sizing: border-box; }
        body { font-family: system-ui, sans-serif; margin: 0; padding: 1.5rem 1rem 3rem; background: #f9fafb; color: #111827; }
        main { max-width: 32rem; margin: 0 auto; }
        h1 { font-size: 1.25rem; margin: 0 0 1rem; }
        a { color: inherit; }
        .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 0.75rem; padding: 1.25rem; margin-bottom: 1rem; }
        .muted { color: #6b7280; font-size: 0.875rem; }
        .meta { display: flex; justify-content: space-between; align-items: center; gap: 0.5rem; }
        .badge { display: inline-block; white-space: nowrap; font-size: 0.75rem; font-weight: 600; padding: 0.125rem 0.625rem; border-radius: 9999px; }
        .badge-gray { background: #f3f4f6; color: #374151; }
        .badge-amber { background: #fef3c7; color: #92400e; }
        .badge-green { background: #d1fae5; color: #065f46; }
        .badge-red { background: #fee2e2; color: #991b1b; }
        .flash { background: #d1fae5; color: #065f46; border-radius: 0.5rem; padding: 0.75rem 1rem; margin-bottom: 1rem; font-size: 0.875rem; }
        .reason { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; border-radius: 0.5rem; padding: 0.75rem 1rem; margin: 0.75rem 0 0; font-size: 0.875rem; }
        .btn { display: inline-block; border: 0; border-radius: 0.5rem; padding: 0.625rem 1rem; font-size: 0.875rem; font-weight: 600; cursor: pointer; text-decoration: none; font-family: inherit; }
        .btn-primary { background: #111827; color: #fff; }
        .btn-danger { background: #fee2e2; color: #991b1b; }
        .actions { margin-top: 1rem; display: flex; gap: 0.5rem; flex-wrap: wrap; }
        .back { display: inline-block; margin-bottom: 0.75rem; color: #6b7280; font-size: 0.875rem; text-decoration: none; }
        .field { margin-bottom: 0.875rem; }
        .field label { display: block; font-size: 0.75rem; color: #6b7280; text-transform: uppercase; margin-bottom: 0.25rem; }
        .field input, .field select, .field textarea { width: 100%; border: 1px solid #d1d5db; border-radius: 0.5rem; padding: 0.625rem 0.75rem; font: inherit; background: #fff; }
        .field .error { color: #b91c1c; font-size: 0.8125rem; margin-top: 0.25rem; }
        dt { font-size: 0.75rem; color: #6b7280; text-transform: uppercase; margin-top: 0.75rem; }
        dd { margin: 0.125rem 0 0; }
        .photos { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 0.5rem; }
        .photos img { width: 5rem; height: 5rem; object-fit: cover; border-radius: 0.5rem; border: 1px solid #e5e7eb; }
        .field .photo-tile { display: flex; flex-direction: column; align-items: center; gap: 0.25rem; margin: 0; font-size: 0.8125rem; color: #6b7280; text-transform: none; }
        .field .photo-tile input { width: auto; }
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
