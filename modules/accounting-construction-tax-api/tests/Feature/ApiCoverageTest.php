<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\ConstructionTaxApi\ConstructionTaxApiServiceProvider;
use Liberu\Accounting\Erp\Models\User;

it('covers accounting-construction-tax-api api provider and all routes', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(ConstructionTaxApiServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(ConstructionTaxApiServiceProvider::class);

    $storeRes = $this->postJson('/api/v1/accounting/construction-tax', [
        'subcontractor_ref' => 'SUB-2026-001',
        'tax_period' => '2026-04',
        'deduction_rate' => 20,
        'gross_amount' => 5000,
    ])->assertCreated();

    $recordId = $storeRes->json('data.id');
    expect($recordId)->not->toBeNull();

    $this->postJson("/api/v1/accounting/construction-tax/{$recordId}/verify", [
        'reference' => 'VER-99001',
        'status' => 'verified',
    ])->assertOk()
        ->assertJsonPath('data.verification_status', 'verified');

    $this->postJson("/api/v1/accounting/construction-tax/{$recordId}/submit", [
        'adapter' => 'hmrc-cis',
    ])->assertOk()
        ->assertJsonPath('data.return_status', 'submitted');

    $this->getJson('/api/v1/accounting/construction-tax')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});
