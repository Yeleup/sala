<x-supplier.layout title="Мои объявления">
    <h1>Мои объявления</h1>

    <article class="card">
        <div class="meta">
            <strong>Ваше имя</strong>
            <span class="muted">{{ $contact->displayName() ?: 'не указано' }}</span>
        </div>
        <form method="POST" action="{{ $updateNameUrl }}" style="margin-top: 0.75rem;">
            @csrf
            <div class="field" style="margin-bottom: 0.75rem;">
                <label for="display_name">Имя</label>
                <input id="display_name" name="display_name" value="{{ old('display_name', $contact->display_name) }}"
                       placeholder="{{ $contact->profile_name ?: 'Как к вам обращаться' }}" maxlength="255">
                @error('display_name')
                    <p class="error">{{ $message }}</p>
                @enderror
                <p class="muted" style="margin: 0.375rem 0 0;">Имя видно во всех ваших объявлениях. Оставьте поле пустым, чтобы использовать имя из WhatsApp.</p>
            </div>
            <button type="submit" class="btn btn-primary">Сохранить имя</button>
        </form>
    </article>

    @forelse ($listings as $listing)
        <article class="card">
            <div class="meta">
                <strong>{{ $listing->category?->name ?: 'Без категории' }}</strong>
                <x-supplier.status-badge :status="$listing->status" />
            </div>
            <p class="muted" style="margin: 0.25rem 0 0;">{{ $listing->type->getLabel() }}</p>

            @if ($listing->description)
                <p style="margin: 0.5rem 0 0;">{{ Str::limit($listing->description, 140) }}</p>
            @endif

            <p class="muted" style="margin: 0.5rem 0 0;">
                {{ collect([$listing->locationLine(), $listing->price])->filter()->join(' · ') ?: 'Локация и цена не указаны' }}
            </p>

            @if ($listing->status === \App\Enums\ListingStatus::Published && $listing->expires_at)
                <p class="muted" style="margin: 0.5rem 0 0;">Опубликовано до {{ $listing->expires_at->format('d.m.Y') }}</p>
            @endif

            @if ($listing->status === \App\Enums\ListingStatus::Rejected && $listing->rejection_reason)
                <p class="reason">Причина отклонения: {{ $listing->rejection_reason }}</p>
            @endif

            <div class="actions">
                @if ($editUrls->has($listing->id))
                    <a class="btn btn-primary" href="{{ $editUrls[$listing->id] }}">
                        {{ $listing->status === \App\Enums\ListingStatus::Rejected ? 'Исправить и отправить снова' : 'Редактировать' }}
                    </a>
                @endif

                @if ($archiveUrls->has($listing->id))
                    <form method="POST" action="{{ $archiveUrls[$listing->id] }}">
                        @csrf
                        <button type="submit" class="btn btn-danger">Снять с публикации</button>
                    </form>
                @endif
            </div>
        </article>
    @empty
        <div class="card">
            <p class="muted" style="margin: 0;">У вас пока нет объявлений. Напишите нашему боту в WhatsApp, чтобы создать первое.</p>
        </div>
    @endforelse
</x-supplier.layout>
