<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\MultiCurrencyApi\MultiCurrencyApiServiceProvider;

it('covers accounting-multi-currency-api provider, controller, and resource', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(MultiCurrencyApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(MultiCurrencyApiServiceProvider::class);

    $this->postJson('/api/v1/accounting/multi-currency/profiles', [
        'team_id' => 1,
        'scope_ref' => 'ENT-1',
        'currency' => 'GBP',
        'role' => 'functional',
        'is_active' => true,
    ])->assertCreated();

    $this->postJson('/api/v1/accounting/multi-currency/rates', [
        'team_id' => 1,
        'from_currency' => 'USD',
        'to_currency' => 'GBP',
        'rate_date' => '2026-02-01',
        'rate' => 0.79,
        'source' => 'ecb',
        'rate_type' => 'spot',
    ])->assertCreated();

    $this->getJson('/api/v1/accounting/multi-currency/rates?team_id=1&from_currency=USD&to_currency=GBP')->assertOk();

    $revalResponse = $this->postJson('/api/v1/accounting/multi-currency/revaluations', [
        'team_id' => 1,
        'run_ref' => 'REV-001',
        'scope_ref' => 'ENT-1',
        'as_of_date' => '2026-02-28',
        'functional_currency' => 'GBP',
        'positions' => [
            [
                'reference_type' => 'invoice',
                'reference_id' => 'INV-100',
                'currency' => 'USD',
                'foreign_amount' => 1000,
                'book_rate' => 0.78,
                'closing_rate' => 0.79,
                'gain_status' => 'unrealized',
            ],
        ],
    ]);
    $revalResponse->assertCreated();
    $runId = $revalResponse->json('data.id');

    $this->getJson("/api/v1/accounting/multi-currency/revaluations/{$runId}")->assertOk();
    $this->getJson("/api/v1/accounting/multi-currency/revaluations/{$runId}/report")->assertOk();
});
