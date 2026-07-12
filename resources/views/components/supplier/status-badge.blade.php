@props(['status'])

@php
    $class = match ($status) {
        \App\Enums\ListingStatus::Published => 'badge-green',
        \App\Enums\ListingStatus::PendingModeration => 'badge-amber',
        \App\Enums\ListingStatus::Rejected => 'badge-red',
        default => 'badge-gray',
    };
@endphp

<span {{ $attributes->merge(['class' => "badge {$class}"]) }}>{{ $status->getLabel() }}</span>
