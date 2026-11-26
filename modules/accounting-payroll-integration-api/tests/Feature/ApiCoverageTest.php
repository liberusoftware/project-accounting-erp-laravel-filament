<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\PayrollIntegrationApi\PayrollIntegrationApiServiceProvider;

it('covers accounting-payroll-integration-api provider, controller, and resource', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(PayrollIntegrationApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(PayrollIntegrationApiServiceProvider::class);

    $storeResponse = $this->postJson('/api/v1/accounting/payroll-integration', [
        'provider' => 'gusto',
        'period_start' => '2026-02-01',
        'period_end' => '2026-02-28',
        'run_ref' => 'PAY-2026-02',
        'currency' => 'GBP',
        'employee_refs' => ['EMP-1'],
    ]);
    $storeResponse->assertCreated();
    $importId = $storeResponse->json('data.id');

    $this->getJson('/api/v1/accounting/payroll-integration')->assertOk();
    $this->getJson('/api/v1/accounting/payroll-integration/summary')->assertOk();
    $this->getJson("/api/v1/accounting/payroll-integration/{$importId}")->assertOk();
    $this->postJson("/api/v1/accounting/payroll-integration/{$importId}/status", [
        'status' => 'validated',
    ])->assertOk();
});
