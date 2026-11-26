<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\CustomReportBuilderApi\CustomReportBuilderApiServiceProvider;
use Liberu\Accounting\Erp\Models\User;

it('covers accounting-custom-report-builder-api api provider, controllers, and routes', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(CustomReportBuilderApiServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(CustomReportBuilderApiServiceProvider::class);

    $store = $this->postJson('/api/v1/accounting/custom-report-builder', [
        'report_ref' => 'RPT-001',
        'name' => 'Revenue by Department',
        'measures' => ['net_revenue'],
        'dimensions' => ['department'],
        'filters' => ['currency' => 'USD'],
        'grouping' => ['department'],
    ]);
    $store->assertStatus(201);
    $reportId = (string) $store->json('data.id');

    $this->getJson('/api/v1/accounting/custom-report-builder')->assertOk();

    $this->postJson("/api/v1/accounting/custom-report-builder/{$reportId}/variants", [
        'variant_ref' => 'VAR-Q1',
        'configuration' => ['period' => '2026-Q1'],
    ])->assertStatus(201);

    $this->postJson("/api/v1/accounting/custom-report-builder/{$reportId}/exports", [
        'format' => 'csv',
        'parameters' => ['include_headers' => true],
    ])->assertStatus(201);
});
