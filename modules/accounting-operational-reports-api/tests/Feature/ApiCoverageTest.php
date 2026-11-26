<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\OperationalReportsApi\OperationalReportsApiServiceProvider;

it('covers accounting-operational-reports-api provider, controller, and resource', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(OperationalReportsApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(OperationalReportsApiServiceProvider::class);

    $storeResponse = $this->postJson('/api/v1/accounting/operational-reports', [
        'team_id' => 1,
        'report_key' => 'ar-aging-2026-q1',
        'name' => 'AR Aging Q1',
        'category' => 'receivables_payables',
        'period_start' => '2026-01-01',
        'period_end' => '2026-03-31',
        'currency' => 'GBP',
        'rows' => [
            ['row_key' => 'cust-1', 'label' => 'Acme Corp', 'amount' => 250.00, 'currency' => 'GBP'],
        ],
        'exceptions' => [
            ['exception_key' => 'overdue-90', 'message' => 'Invoice overdue over 90 days'],
        ],
    ]);
    $storeResponse->assertCreated();
    $runId = $storeResponse->json('data.id');

    $this->getJson('/api/v1/accounting/operational-reports?team_id=1')->assertOk();
    $this->getJson('/api/v1/accounting/operational-reports/exceptions?team_id=1')->assertOk();
    $this->getJson("/api/v1/accounting/operational-reports/{$runId}")->assertOk();
    $this->postJson("/api/v1/accounting/operational-reports/{$runId}/publish")->assertOk();
});
