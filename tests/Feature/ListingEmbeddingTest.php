<?php

use App\Enums\AiCostStatus;
use App\Enums\AiOperationStatus;
use App\Enums\AiOperationType;
use App\Jobs\GenerateListingEmbedding;
use App\Models\AiOperation;
use App\Models\Listing;
use App\Models\ListingEmbedding;
use App\Services\Ai\Audit\AiAudit;
use App\Services\Ai\ListingEmbeddings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\EmbeddingsResponse;

uses(RefreshDatabase::class);

function runEmbeddingJob(Listing $listing): void
{
    (new GenerateListingEmbedding($listing))->handle(app(ListingEmbeddings::class), app(AiAudit::class));
}

function fakeEmbeddingResponse(int $tokens = 120): void
{
    Embeddings::fake(function () use ($tokens): EmbeddingsResponse {
        $vector = array_fill(0, ListingEmbeddings::DIMENSIONS, 0.0);
        $vector[3] = 1.0;

        return new EmbeddingsResponse([$vector], $tokens, new Meta('openai', 'text-embedding-3-small'));
    });
}

test('одобрение объявления ставит генерацию эмбеддинга в очередь', function () {
    Queue::fake();
    $listing = Listing::factory()->pendingModeration()->create();

    $listing->approve();

    Queue::assertPushed(
        GenerateListingEmbedding::class,
        fn (GenerateListingEmbedding $job): bool => $job->listing->is($listing),
    );
});

test('правка искомого текста опубликованного объявления обновляет эмбеддинг', function () {
    Queue::fake();
    $listing = Listing::factory()->published()->create();

    $listing->update(['description' => 'Обновлённое описание крана']);
    $listing->update(['location_detail' => 'мкр Нурсат']);
    $listing->update(['title' => 'Аренда автокрана 25 т']);

    Queue::assertPushed(GenerateListingEmbedding::class, 3);
});

test('черновик, продление и архивация не запускают генерацию', function () {
    Queue::fake();

    $draft = Listing::factory()->create();
    $draft->update(['description' => 'Черновик правится']);

    $published = Listing::factory()->published()->create();
    $published->renew();
    $published->archive();

    Queue::assertNothingPushed();
});

test('джоба сохраняет вектор и пишет операцию с попыткой и стоимостью', function () {
    fakeEmbeddingResponse(tokens: 120);
    $listing = Listing::factory()->published()->create();

    runEmbeddingJob($listing);

    $stored = ListingEmbedding::query()->sole();
    $fresh = $listing->fresh(['category', 'brand', 'location']);
    expect($stored->listing_id)->toBe($listing->id)
        ->and($stored->model)->toBe('text-embedding-3-small')
        ->and($stored->source_hash)->toBe(app(ListingEmbeddings::class)->sourceHash($fresh, 'text-embedding-3-small'));

    $operation = AiOperation::query()->sole();
    expect($operation->operation)->toBe(AiOperationType::Embedding)
        ->and($operation->status)->toBe(AiOperationStatus::Completed)
        ->and($operation->listing_id)->toBe($listing->id)
        ->and($operation->contact_id)->toBe($listing->contact_id);

    $attempt = $operation->attempts()->sole();
    expect($attempt->input_tokens)->toBe(120)
        ->and($attempt->model)->toBe('text-embedding-3-small')
        ->and($attempt->cost_status)->toBe(AiCostStatus::Estimated)
        ->and((float) $attempt->estimated_cost_usd)->toBeGreaterThan(0);
});

test('повторный запуск без изменений не тратит вызов провайдера', function () {
    fakeEmbeddingResponse();
    $listing = Listing::factory()->published()->create();

    runEmbeddingJob($listing);
    runEmbeddingJob($listing);

    expect(ListingEmbedding::query()->count())->toBe(1)
        ->and(AiOperation::query()->count())->toBe(1);
});

test('джоба пропускает объявление, ушедшее из публикации', function () {
    fakeEmbeddingResponse();
    $listing = Listing::factory()->archived()->create();

    runEmbeddingJob($listing);

    expect(ListingEmbedding::query()->count())->toBe(0)
        ->and(AiOperation::query()->count())->toBe(0);
});

test('текст для эмбеддинга включает тип, название, категорию, марку, описание и локацию, но не цену', function () {
    $listing = Listing::factory()->published()->create([
        'title' => 'Аренда автокрана 25 т',
        'category_id' => categoryNamed('Автокран')->id,
        'brand_id' => brandNamed('Kato')->id,
        'description' => 'Стрела 28 метров',
        'location_id' => locationNamed('г.Шымкент')->id,
        'location_detail' => 'центр',
        'price' => '20000 тг/ч',
    ]);

    $text = app(ListingEmbeddings::class)->sourceText($listing);

    expect($text)
        ->toContain('Техника')
        ->toContain('Название: Аренда автокрана 25 т')
        ->toContain('Автокран')
        ->toContain('Kato')
        ->toContain('Стрела 28 метров')
        ->toContain('г.Шымкент, центр')
        ->not->toContain('20000');
});

test('объявление без названия не получает строку названия в тексте эмбеддинга', function () {
    $listing = Listing::factory()->published()->create(['title' => null]);

    expect(app(ListingEmbeddings::class)->sourceText($listing))->not->toContain('Название:');
});

test('сквозной сценарий: одобрение создаёт эмбеддинг через очередь', function () {
    fakeEmbeddingResponse();
    $listing = Listing::factory()->pendingModeration()->create();

    $listing->approve();

    expect(ListingEmbedding::query()->where('listing_id', $listing->id)->exists())->toBeTrue();
});
