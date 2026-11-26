<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\OpeningBalances\Models\OpeningBalanceBatch;
use Liberu\Accounting\OpeningBalancesApi\OpeningBalancesApiServiceProvider;

it('covers accounting-opening-balances-api provider, controller, and resource', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(OpeningBalancesApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(OpeningBalancesApiServiceProvider::class);

    $storeResponse = $this->postJson('/api/v1/accounting/opening-balances', [
        'team_id' => 1,
        'batch_ref' => 'OB-001',
        'migration_date' => '2026-01-01',
        'currency' => 'GBP',
        'entries' => [
            [
                'balance_type' => 'account',
                'reference_id' => '1000',
                'debit_amount' => 1000.00,
                'credit_amount' => 0.00,
                'currency' => 'GBP',
            ],
            [
                'balance_type' => 'account',
                'reference_id' => '3000',
                'debit_amount' => 0.00,
                'credit_amount' => 1000.00,
                'currency' => 'GBP',
            ],
        ],
    ]);
    $storeResponse->assertCreated();
    $batchId = $storeResponse->json('data.id');

    $this->getJson('/api/v1/accounting/opening-balances?team_id=1')->assertOk();
    $this->getJson("/api/v1/accounting/opening-balances/{$batchId}")->assertOk();
    $this->postJson("/api/v1/accounting/opening-balances/{$batchId}/validate")->assertOk();
    $this->postJson("/api/v1/accounting/opening-balances/{$batchId}/approve")->assertOk();

    $entry = OpeningBalanceBatch::query()->findOrFail($batchId)->entries()->firstOrFail();

    $this->postJson("/api/v1/accounting/opening-balances/{$batchId}/reconcile", [
        'actuals' => [
            [
                'entry_id' => $entry->getKey(),
                'actual_amount' => 1000.00,
                'external_ref' => 'TB-1000',
                'notes' => 'Matched trial balance',
            ],
        ],
    ])->assertOk();
});
