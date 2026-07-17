<?php

use App\Models\Listing;
use App\Models\ListingEmbedding;
use App\Services\Ai\ListingEmbeddings;
use App\Services\Ai\ListingMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;

uses(RefreshDatabase::class);

/**
 * Единичный вектор вдоль одной оси: два текста «семантически близки»
 * ровно тогда, когда закреплены за одной осью (косинус 1.0), иначе 0.
 *
 * @return array<float>
 */
function oneHotVector(int $axis, float $weight = 1.0): array
{
    $vector = array_fill(0, ListingEmbeddings::DIMENSIONS, 0.0);
    $vector[$axis] = $weight;

    if ($weight < 1.0) {
        // Добираем длину до единичной в «чужой» оси — косинус с oneHotVector($axis) равен ровно $weight.
        $vector[($axis + 1) % ListingEmbeddings::DIMENSIONS] = sqrt(1 - $weight ** 2);
    }

    return $vector;
}

/**
 * Детерминированное «семантическое пространство»: текст с известной
 * подстрокой получает назначенную ось, любой другой — ось из хэша текста
 * (разные тексты почти наверняка не совпадут).
 *
 * @param  array<string, int>  $axisBySubstring
 */
function fakeSemanticSpace(array $axisBySubstring = []): void
{
    Embeddings::fake(function (EmbeddingsPrompt $prompt) use ($axisBySubstring): array {
        return array_map(function (string $input) use ($axisBySubstring): array {
            foreach ($axisBySubstring as $substring => $axis) {
                if (Str::contains(Str::lower($input), Str::lower($substring))) {
                    return oneHotVector($axis);
                }
            }

            return oneHotVector(crc32($input) % ListingEmbeddings::DIMENSIONS);
        }, $prompt->inputs);
    });
}

/**
 * @param  array<float>  $vector
 */
function embedListing(Listing $listing, array $vector): ListingEmbedding
{
    return ListingEmbedding::create([
        'listing_id' => $listing->id,
        'embedding' => '['.implode(',', $vector).']',
        'source_hash' => 'seeded-in-test',
        'model' => 'text-embedding-3-small',
    ]);
}

test('семантически близкое объявление находится без единого общего слова', function () {
    fakeSemanticSpace(['яму' => 7]);

    $shymkent = locationNamed('г.Шымкент');
    $excavator = Listing::factory()->published()->create([
        'category_id' => categoryNamed('Экскаватор')->id,
        'description' => 'Гусеничный, для земляных работ',
        'location_id' => $shymkent->id,
        'price' => '20000 тг/ч',
    ]);
    $truck = Listing::factory()->published()->create([
        'category_id' => categoryNamed('Самосвал')->id,
        'description' => 'Перевозка сыпучих материалов',
        'location_id' => $shymkent->id,
        'price' => '15000 тг/ч',
    ]);
    embedListing($excavator, oneHotVector(7));
    embedListing($truck, oneHotVector(200));

    $matches = app(ListingMatcher::class)->match('надо выкопать глубокую яму под фундамент');

    expect($matches->pluck('id')->all())->toBe([$excavator->id]);
});

test('совпадение слов поднимает объявление выше чисто семантического', function () {
    fakeSemanticSpace(['экскаватор' => 3]);

    $shymkent = locationNamed('г.Шымкент');
    $hitachi = Listing::factory()->published()->create([
        'category_id' => categoryNamed('Экскаватор')->id,
        'brand_id' => brandNamed('Hitachi')->id,
        'description' => 'Гусеничный',
        'location_id' => $shymkent->id,
        'price' => '20000 тг/ч',
        'created_at' => now()->subDay(),
    ]);
    $noBrand = Listing::factory()->published()->create([
        'category_id' => categoryNamed('Экскаватор')->id,
        'brand_id' => null,
        'description' => 'Колёсный',
        'location_id' => $shymkent->id,
        'price' => '20000 тг/ч',
    ]);
    embedListing($hitachi, oneHotVector(3));
    embedListing($noBrand, oneHotVector(3));

    $matches = app(ListingMatcher::class)->match('нужен экскаватор Hitachi');

    expect($matches->pluck('id')->all())->toBe([$hitachi->id, $noBrand->id]);
});

test('похожесть ниже порога без совпавших слов не попадает в выдачу', function () {
    fakeSemanticSpace(['вертолёт' => 9]);

    $shymkent = locationNamed('г.Шымкент');
    $below = Listing::factory()->published()->create([
        'category_id' => categoryNamed('Автокран')->id,
        'description' => 'Стрела 28 метров',
        'location_id' => $shymkent->id,
        'price' => '20000 тг/ч',
    ]);
    $above = Listing::factory()->published()->create([
        'category_id' => categoryNamed('Манипулятор')->id,
        'description' => 'Борт 5 тонн',
        'location_id' => $shymkent->id,
        'price' => '15000 тг/ч',
    ]);
    embedListing($below, oneHotVector(9, ListingMatcher::MIN_SIMILARITY - 0.05));
    embedListing($above, oneHotVector(9, ListingMatcher::MIN_SIMILARITY + 0.05));

    $matches = app(ListingMatcher::class)->match('вертолёт');

    expect($matches->pluck('id')->all())->toBe([$above->id]);
});

test('пустая выдача возможна: ни слов, ни похожести', function () {
    fakeSemanticSpace(['вертолёт' => 9]);

    $listing = Listing::factory()->published()->create([
        'category_id' => categoryNamed('Автокран')->id,
        'description' => 'Стрела 28 метров',
        'location_id' => locationNamed('г.Шымкент')->id,
        'price' => '20000 тг/ч',
    ]);
    embedListing($listing, oneHotVector(50));

    expect(app(ListingMatcher::class)->match('вертолёт'))->toBeEmpty();
});

test('объявление без эмбеддинга находится по словам в гибридном режиме', function () {
    fakeSemanticSpace();

    $crane = Listing::factory()->published()->create([
        'category_id' => categoryNamed('Автокран')->id,
        'description' => 'Стрела 28 метров',
        'location_id' => locationNamed('г.Шымкент')->id,
    ]);

    $matches = app(ListingMatcher::class)->match('автокран');

    expect($matches->pluck('id')->all())->toBe([$crane->id]);
});

test('при сбое генерации эмбеддинга поиск деградирует до слов', function () {
    Embeddings::fake(function (): never {
        throw new RuntimeException('provider down');
    });

    $crane = Listing::factory()->published()->create([
        'category_id' => categoryNamed('Автокран')->id,
        'description' => 'Стрела 28 метров',
        'location_id' => locationNamed('г.Шымкент')->id,
    ]);

    $matches = app(ListingMatcher::class)->match('автокран');

    expect($matches->pluck('id')->all())->toBe([$crane->id]);
});

test('фильтр по поддереву локации действует и в гибридном режиме', function () {
    fakeSemanticSpace(['спецтехника' => 11]);

    $shymkent = locationNamed('г.Шымкент');
    $inShymkent = Listing::factory()->published()->create([
        'category_id' => categoryNamed('Автокран')->id,
        'description' => 'Стрела 28 метров',
        'location_id' => $shymkent->id,
    ]);
    $inAstana = Listing::factory()->published()->create([
        'category_id' => categoryNamed('Автокран')->id,
        'description' => 'Стрела 32 метра',
        'location_id' => locationNamed('г.Астана')->id,
    ]);
    embedListing($inShymkent, oneHotVector(11));
    embedListing($inAstana, oneHotVector(11));

    $matches = app(ListingMatcher::class)->match('спецтехника', $shymkent);

    expect($matches->pluck('id')->all())->toBe([$inShymkent->id]);
});
