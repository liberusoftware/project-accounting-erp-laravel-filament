<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\ManagementReportingApi\ManagementReportingApiServiceProvider;

it('covers accounting-management-reporting-api provider, controller, and resource', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(ManagementReportingApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(ManagementReportingApiServiceProvider::class);

    $storeResponse = $this->postJson('/api/v1/accounting/management-reporting/packs', [
        'team_id' => 1,
        'report_ref' => 'REP-001',
        'name' => 'Q1 Board Pack',
        'period_start' => '2026-01-01',
        'period_end' => '2026-03-31',
        'currency' => 'GBP',
        'owner_ref' => 'CFO-1',
    ]);
    $storeResponse->assertSuccessful();
    $packId = $storeResponse->json('data.id');

    $this->getJson('/api/v1/accounting/management-reporting/packs?team_id=1')->assertOk();
    $this->getJson("/api/v1/accounting/management-reporting/packs/{$packId}")->assertOk();

    $this->postJson("/api/v1/accounting/management-reporting/packs/{$packId}/review", [
        'decision' => 'approved',
        'comment' => 'Approved for distribution',
    ])->assertOk();

    $this->postJson("/api/v1/accounting/management-reporting/packs/{$packId}/deliver", [
        'format' => 'pdf',
        'file_ref' => 'reports/q1-pack.pdf',
        'recipients' => ['board@example.com'],
    ])->assertCreated();

    $this->postJson("/api/v1/accounting/management-reporting/packs/{$packId}/archive")->assertOk();
});
