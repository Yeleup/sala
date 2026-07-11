<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Редактирование объявления</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 0; padding: 2rem 1.25rem; background: #f9fafb; color: #111827; }
        .card { max-width: 32rem; margin: 0 auto; background: #fff; border: 1px solid #e5e7eb; border-radius: 0.75rem; padding: 1.5rem; }
        h1 { font-size: 1.125rem; margin: 0 0 0.75rem; }
        dl { margin: 1rem 0 0; }
        dt { font-size: 0.75rem; color: #6b7280; text-transform: uppercase; margin-top: 0.75rem; }
        dd { margin: 0.125rem 0 0; }
        .muted { color: #6b7280; font-size: 0.875rem; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Ваше объявление</h1>
        <p class="muted">Веб-редактирование появится здесь позже. Пока это предпросмотр распознанных данных.</p>
        <dl>
            <dt>Категория</dt>
            <dd>{{ $listing->category ?: '—' }}</dd>
            <dt>Описание</dt>
            <dd>{{ $listing->description ?: '—' }}</dd>
            <dt>Локация</dt>
            <dd>{{ $listing->location ?: '—' }}</dd>
            <dt>Цена</dt>
            <dd>{{ $listing->price ?: '—' }}</dd>
        </dl>
    </div>
</body>
</html>
