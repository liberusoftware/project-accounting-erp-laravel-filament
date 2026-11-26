<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\TaxReturns\Enums\TaxReturnStatus;
use Liberu\Accounting\TaxReturns\Models\TaxReturn;
use Liberu\Accounting\TaxReturnsApi\TaxReturnsApiServiceProvider;

it('covers accounting-tax-returns-api provider and controller', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(TaxReturnsApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(TaxReturnsApiServiceProvider::class);

    $storeResponse = $this->postJson('/api/v1/accounting/tax-returns', [
        'tax_type' => 'vat',
        'jurisdiction' => 'GB',
        'period_start' => '2026-01-01',
        'period_end' => '2026-03-31',
        'due_on' => '2026-05-07',
    ]);
    $storeResponse->assertCreated();
    $returnId = $storeResponse->json('id');

    $this->getJson('/api/v1/accounting/tax-returns')->assertOk();
    $this->postJson("/api/v1/accounting/tax-returns/{$returnId}/submit", [
        'external_reference' => 'HMRC-2026-Q1',
    ])->assertOk();
    TaxReturn::query()->whereKey($returnId)->update(['status' => TaxReturnStatus::Accepted]);
    $this->postJson("/api/v1/accounting/tax-returns/{$returnId}/amend")->assertSuccessful();
});
