<?php

use App\Ai\Agents\ListingExtractionAgent;
use App\Enums\AiAttemptStatus;
use App\Enums\AiCostStatus;
use App\Enums\AiOperationStatus;
use App\Enums\AiOperationType;
use App\Models\AiAttempt;
use App\Models\AiOperation;
use App\Models\BotSession;
use App\Models\Contact;
use App\Services\Ai\Audit\AiAudit;
use App\Services\Ai\Audit\AiAuditState;
use App\Services\Ai\Audit\AiCostEstimator;
use App\Services\Ai\SupplierListingCollector;
use App\Services\Bot\InboundMessage;
use App\Services\DereuMessenger;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('оценка стоимости', function () {
    beforeEach(function () {
        config()->set('ai-pricing.models.test-model', [
            'input' => 2.0, 'output' => 10.0, 'cache_read' => 1.0, 'cache_write' => null,
        ]);
    });

    test('стоимость считается по снапшоту тарифа', function () {
        $estimate = app(AiCostEstimator::class)->estimate('test-model', 1_000_000, 100_000, 200_000);

        expect($estimate['cost_status'])->toBe(AiCostStatus::Estimated)
            ->and($estimate['estimated_cost_usd'])->toBe('3.200000') // 2.0 + 1.0 + 0.2
            ->and($estimate['pricing_snapshot']['input'])->toBe(2.0);
    });

    test('неизвестная модель даёт cost_status=unknown, а не ноль', function () {
        $estimate = app(AiCostEstimator::class)->estimate('mystery-model', 1000, 100);

        expect($estimate['cost_status'])->toBe(AiCostStatus::Unknown)
            ->and($estimate['estimated_cost_usd'])->toBeNull();
    });

    test('использованный вид токенов без тарифа делает стоимость неизвестной', function () {
        $estimate = app(AiCostEstimator::class)->estimate('test-model', 1000, 100, cacheWriteTokens: 50);

        expect($estimate['cost_status'])->toBe(AiCostStatus::Unknown);
    });

    test('нулевой usage не превращается в нулевую стоимость', function () {
        $estimate = app(AiCostEstimator::class)->estimate('test-model', 0, 0);

        expect($estimate['cost_status'])->toBe(AiCostStatus::Unknown)
            ->and($estimate['estimated_cost_usd'])->toBeNull();
    });
});

describe('обёртка AiAudit', function () {
    test('успешная операция закрывается со статусом completed и связями', function () {
        $contact = Contact::factory()->create();

        $result = app(AiAudit::class)->run(
            AiOperationType::Transcription,
            fn (): string => 'готово',
            ['contact_id' => $contact->id],
        );

        expect($result)->toBe('готово')
            ->and(AiOperation::sole())
            ->operation->toBe(AiOperationType::Transcription)
            ->status->toBe(AiOperationStatus::Completed)
            ->contact_id->toBe($contact->id);
    });

    test('исключение фиксирует операцию failed и закрывает начатый вызов failed-попыткой', function () {
        $state = app(AiAuditState::class);

        expect(function () use ($state) {
            app(AiAudit::class)->run(AiOperationType::ListingExtraction, function () use ($state): never {
                $state->begin('inv-9', 'prompt-text', 'openai', 'gpt-5.4');

                throw new RuntimeException('API недоступен');
            });
        })->toThrow(RuntimeException::class);

        expect(AiOperation::sole())
            ->status->toBe(AiOperationStatus::Failed)
            ->error->toContain('API недоступен');

        expect(AiAttempt::sole())
            ->status->toBe(AiAttemptStatus::Failed)
            ->invocation_id->toBe('inv-9')
            ->provider->toBe('openai')
            ->prompt->toBe('prompt-text')
            ->cost_status->toBe(AiCostStatus::Unknown)
            ->and(AiAttempt::sole()->latency_ms)->not->toBeNull();
    });
});

describe('интеграция с коллектором', function () {
    test('извлечение объявления оставляет операцию с попыткой, токенами и связями', function () {
        ListingExtractionAgent::fake([[
            'type' => 'equipment',
            'category' => 'Трактор',
            'description' => 'Трактор в аренду',
            'location' => 'Шымкент',
            'price' => '10000 тг/час',
            'clarifying_question' => '',
            'summary' => 'Трактор, Шымкент',
        ]]);

        $session = BotSession::factory()->waitingAt('collect')->create([
            'state' => [
                'phase' => 'collecting', 'attempts' => 0, 'transcript' => [],
                'fields' => [], 'draft_id' => null, 'listing_type' => 'equipment',
            ],
        ]);
        test()->mock(DereuMessenger::class)->shouldReceive('sendButtons')->once();

        app(SupplierListingCollector::class)->resume(
            $session,
            ['id' => 'collect', 'type' => 'ai', 'task' => 'collect_listing', 'listing_type' => 'equipment'],
            new InboundMessage(text: 'Сдаю трактор в Шымкенте, 10000 тг/час'),
        );

        $operation = AiOperation::sole();
        expect($operation)
            ->operation->toBe(AiOperationType::ListingExtraction)
            ->status->toBe(AiOperationStatus::Completed)
            ->contact_id->toBe($session->contact_id)
            ->bot_session_id->toBe($session->id);

        $attempt = $operation->attempts()->sole();
        expect($attempt)
            ->status->toBe(AiAttemptStatus::Succeeded)
            ->invocation_id->not->toBeNull()
            ->and($attempt->prompt)->toContain('Сдаю трактор')
            ->and($attempt->latency_ms)->not->toBeNull();
    });
});
