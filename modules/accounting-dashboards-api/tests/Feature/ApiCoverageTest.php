<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\DashboardsApi\DashboardsApiServiceProvider;
use Liberu\Accounting\Erp\Models\User;

it('covers accounting-dashboards-api api provider, controllers, and routes', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(DashboardsApiServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(DashboardsApiServiceProvider::class);

    $store = $this->postJson('/api/v1/accounting/dashboards', [
        'dashboard_ref' => 'DASH-FIN-01',
        'name' => 'Executive Finance Dashboard',
        'role' => 'cfo',
        'period' => '2026-Q1',
        'dimensions' => ['department' => 'FIN'],
    ]);
    $store->assertStatus(201);
    $dashboardId = (string) $store->json('data.id');

    $this->getJson('/api/v1/accounting/dashboards')->assertOk();

    $this->postJson("/api/v1/accounting/dashboards/{$dashboardId}/kpis", [
        'kpi_ref' => 'KPI-REV',
        'label' => 'Monthly Revenue',
        'value' => 125000.00,
        'target' => 120000.00,
        'unit' => 'USD',
        'drill_through' => ['report' => 'pnl'],
    ])->assertStatus(201);

    $this->postJson("/api/v1/accounting/dashboards/{$dashboardId}/shares", [
        'shared_with_user_id' => $user->getKey(),
        'shared_with_role' => 'controller',
        'expires_at' => '2026-12-31',
    ])->assertStatus(201);
});
