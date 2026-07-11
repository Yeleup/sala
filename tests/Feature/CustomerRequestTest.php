<?php

use App\Enums\CustomerRequestStatus;
use App\Models\CustomerRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
