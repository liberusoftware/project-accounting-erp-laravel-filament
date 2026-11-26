<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\YearEndApi\YearEndApiServiceProvider;

it('covers accounting-year-end-api provider and controller', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(YearEndApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(YearEndApiServiceProvider::class);

    $storeResponse = $this->postJson('/api/v1/accounting/year-end', [
        'period_ref' => 'FY-2026',
        'period_end' => '2026-12-31',
    ]);
    $storeResponse->assertCreated();
    $periodId = $storeResponse->json('data.id');

    $this->getJson('/api/v1/accounting/year-end')->assertOk();

    $this->postJson("/api/v1/accounting/year-end/{$periodId}/adjustments", [
        'adjustment_ref' => 'ADJ-001',
        'amount' => 1500.00,
        'description' => 'Accrued year-end audit fee',
        'journal_ref' => 'JRN-YE-001',
    ])->assertCreated();

    $this->postJson("/api/v1/accounting/year-end/{$periodId}/lock")->assertOk();
    $this->postJson("/api/v1/accounting/year-end/{$periodId}/archive")->assertOk();
});
