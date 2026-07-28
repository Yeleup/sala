<?php

use App\Models\ListingMedia;

test('адрес фотографии для CSS теряет символы, которыми можно выйти из url()', function () {
    $media = new ListingMedia(['disk' => 'public', 'path' => "a'b\"c(d)e\\f.jpg"]);

    expect($media->cssUrl())
        ->not->toContain("'")
        ->not->toContain('"')
        ->not->toContain('(')
        ->not->toContain(')')
        ->not->toContain('\\')
        ->toContain('abcdef.jpg');
});

test('обычный адрес фотографии в CSS не меняется', function () {
    $media = new ListingMedia(['disk' => 'public', 'path' => 'listing-media/photo-1.jpg']);

    expect($media->cssUrl())->toBe($media->url());
});
