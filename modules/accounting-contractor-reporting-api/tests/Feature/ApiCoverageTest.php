<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\ContractorReportingApi\ContractorReportingApiServiceProvider;
use Liberu\Accounting\Erp\Models\User;

it('covers accounting-contractor-reporting-api api provider and all routes', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(ContractorReportingApiServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(ContractorReportingApiServiceProvider::class);

    $storeRes = $this->postJson('/api/v1/accounting/contractor-reporting', [
        'payee_ref' => 'PAY-2026-001',
        'tax_year' => '2026',
        'classification' => '1099-nec',
        'threshold' => 600,
        'reportable_amount' => 3500,
        'form_type' => '1099-NEC',
        'evidence' => [['ref' => 'INV-2026-1']],
    ])->assertCreated();

    $reportId = $storeRes->json('data.id');
    expect($reportId)->not->toBeNull();

    $this->postJson("/api/v1/accounting/contractor-reporting/{$reportId}/validate", [
        'tax_id' => '12-3456789',
        'legal_name' => 'Jane Contractor LLC',
    ])->assertOk()
        ->assertJsonPath('data.status', 'validated');

    $this->postJson("/api/v1/accounting/contractor-reporting/{$reportId}/file", [
        'adapter' => 'irs-fire',
    ])->assertOk()
        ->assertJsonPath('data.status', 'filed');

    $this->getJson('/api/v1/accounting/contractor-reporting')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});
