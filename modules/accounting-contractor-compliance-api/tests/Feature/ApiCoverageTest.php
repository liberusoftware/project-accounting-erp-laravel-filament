<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\ContractorComplianceApi\ContractorComplianceApiServiceProvider;
use Liberu\Accounting\Erp\Models\User;

it('covers accounting-contractor-compliance-api api provider and all routes', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(ContractorComplianceApiServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(ContractorComplianceApiServiceProvider::class);

    $storeRes = $this->postJson('/api/v1/accounting/contractor-compliance', [
        'contractor_ref' => 'CON-2026-001',
        'legal_name' => 'Jane Builder Ltd',
        'classification' => 'subcontractor',
        'withholding_scheme' => 'CIS',
    ])->assertCreated();

    $contractorId = $storeRes->json('data.id');
    expect($contractorId)->not->toBeNull();

    $this->postJson("/api/v1/accounting/contractor-compliance/{$contractorId}/evidence", [
        'type' => 'tax-certificate',
        'reference' => 'EV-2026-001',
    ])->assertOk();

    $this->postJson("/api/v1/accounting/contractor-compliance/{$contractorId}/statement", [
        'period' => '2026-04',
        'amount' => 2500,
    ])->assertOk();

    $this->postJson("/api/v1/accounting/contractor-compliance/{$contractorId}/export", [
        'region' => 'UK',
    ])->assertOk();

    $this->getJson('/api/v1/accounting/contractor-compliance')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});
