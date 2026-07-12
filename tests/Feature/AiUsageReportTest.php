<?php

use App\Filament\Pages\AiUsageReport;
use App\Models\AiAttempt;
use App\Models\AiOperation;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('отчёт показывает сводку, разрезы и топ контактов за период', function () {
    $contact = Contact::factory()->create(['phone' => '77019990000', 'profile_name' => 'Аскар']);
    $operation = AiOperation::factory()->create(['contact_id' => $contact->id]);

    AiAttempt::factory()->for($operation, 'operation')->create([
        'model' => 'gpt-5.4',
        'estimated_cost_usd' => '0.012345',
        'latency_ms' => 900,
        'input_tokens' => 1200,
        'output_tokens' => 300,
    ]);
    AiAttempt::factory()->for($operation, 'operation')->failed('Timeout')->create(['model' => 'gpt-5.4']);
    AiAttempt::factory()->for(AiOperation::factory()->transcription(), 'operation')->unknownCost()->create();

    Livewire::test(AiUsageReport::class)
        ->assertOk()
        ->assertSee('Извлечение объявления')
        ->assertSee('Транскрибация аудио')
        ->assertSee('gpt-5.4')
        ->assertSee('experimental-model')
        ->assertSee('без тарифа')
        ->assertSee('Аскар')
        ->assertSee('+77019990000');
});

test('период отсекает старые вызовы', function () {
    AiAttempt::factory()->create(['created_at' => now()->subDays(40), 'model' => 'ancient-model']);

    Livewire::test(AiUsageReport::class)
        ->assertOk()
        ->assertDontSee('ancient-model')
        ->set('days', 90)
        ->assertSee('ancient-model');
});

test('пустой период не ломает страницу', function () {
    Livewire::test(AiUsageReport::class)
        ->assertOk()
        ->assertSee('За период вызовов не было.');
});
