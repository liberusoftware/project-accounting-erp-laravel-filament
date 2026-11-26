<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\RecurringTransactionsApi\RecurringTransactionsApiServiceProvider;

it('covers accounting-recurring-transactions-api provider and controller', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(RecurringTransactionsApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(RecurringTransactionsApiServiceProvider::class);

    $storeResponse = $this->postJson('/api/v1/accounting/recurring-transactions', [
        'name' => 'Monthly Rent',
        'transaction_type' => 'journal',
        'frequency' => 'monthly',
        'starts_on' => '2026-01-01',
        'next_run_on' => '2026-01-01',
        'automatic' => true,
        'payload' => ['amount' => 2000.00, 'currency' => 'GBP'],
    ]);
    $storeResponse->assertCreated();
    $templateId = $storeResponse->json('id');

    $this->getJson('/api/v1/accounting/recurring-transactions')->assertOk();
    $this->getJson("/api/v1/accounting/recurring-transactions/{$templateId}")->assertOk();
    $this->postJson("/api/v1/accounting/recurring-transactions/{$templateId}/approve")->assertOk();
    $this->postJson("/api/v1/accounting/recurring-transactions/{$templateId}/generate", [
        'through_date' => '2026-02-01',
    ])->assertOk();
});
