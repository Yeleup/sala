<?php

use App\Enums\CustomerRequestStatus;
use App\Filament\Resources\CustomerRequests\Pages\ListCustomerRequests;
use App\Models\CustomerRequest;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('a pending request can be accepted by the supplier', function () {
    $request = CustomerRequest::factory()->create();

    $request->accept();

    expect($request->refresh()->status)->toBe(CustomerRequestStatus::Accepted);
});

test('a pending request can be declined by the supplier', function () {
    $request = CustomerRequest::factory()->create();

    $request->decline();

    expect($request->refresh()->status)->toBe(CustomerRequestStatus::Declined);
});

test('a decided request cannot be changed', function () {
    CustomerRequest::factory()->accepted()->create()->decline();
})->throws(LogicException::class);

test('a pending request can be expired', function () {
    $request = CustomerRequest::factory()->create();

    $request->expire();

    expect($request->refresh()->status)->toBe(CustomerRequestStatus::Expired);
});

test('a decided request cannot be expired', function () {
    CustomerRequest::factory()->accepted()->create()->expire();
})->throws(LogicException::class);

test('решение атомарно: параллельно закрытая заявка не принимается задним числом', function () {
    $request = CustomerRequest::factory()->create();
    $stale = CustomerRequest::query()->find($request->id); // второй процесс со своим снимком

    $request->expire();

    // Снимок второго процесса ещё «видит» Pending — гвард обязан
    // смотреть в базу, а не в память, иначе гонка перезапишет статус.
    expect(fn () => $stale->accept())->toThrow(LogicException::class)
        ->and($request->refresh()->status)->toBe(CustomerRequestStatus::Expired);
});

test('оператор закрывает залипшую заявку действием «Закрыть без ответа»', function () {
    $this->actingAs(User::factory()->create());
    $request = CustomerRequest::factory()->create();

    Livewire::test(ListCustomerRequests::class)
        ->callAction(TestAction::make('expire')->table($request));

    expect($request->refresh()->status)->toBe(CustomerRequestStatus::Expired);
});

test('решённой заявке действие «Закрыть без ответа» недоступно', function () {
    $this->actingAs(User::factory()->create());
    $request = CustomerRequest::factory()->accepted()->create();

    Livewire::test(ListCustomerRequests::class)
        ->assertActionHidden(TestAction::make('expire')->table($request));

    expect($request->refresh()->status)->toBe(CustomerRequestStatus::Accepted);
});
