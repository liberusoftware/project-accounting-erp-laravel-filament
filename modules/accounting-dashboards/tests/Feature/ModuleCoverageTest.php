<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Liberu\Accounting\Dashboards\Actions\CreateDashboard;
use Liberu\Accounting\Dashboards\Actions\ShareDashboard;
use Liberu\Accounting\Dashboards\Actions\UpsertKpi;
use Liberu\Accounting\Dashboards\DashboardsServiceProvider;
use Liberu\Accounting\Dashboards\Exceptions\InvalidDashboard;
use Liberu\Accounting\Dashboards\Models\Dashboard;
use Liberu\Accounting\Dashboards\Models\DashboardKpi;
use Liberu\Accounting\Dashboards\Models\DashboardShare;
use Liberu\Accounting\Dashboards\Queries\DashboardQuery;

it('registers and boots the dashboards service provider and migrations', function (): void {
    $provider = app()->getProvider(DashboardsServiceProvider::class)
        ?? new DashboardsServiceProvider(app());

    $provider->register();
    $provider->boot();

    expect($provider)->toBeInstanceOf(DashboardsServiceProvider::class)
        ->and(Schema::hasTable('accounting_dashboards'))->toBeTrue()
        ->and(Schema::hasTable('accounting_dashboard_kpis'))->toBeTrue()
        ->and(Schema::hasTable('accounting_dashboard_shares'))->toBeTrue();
});

it('exposes the invalid dashboard domain exception', function (): void {
    $exception = new InvalidDashboard('Missing dashboard ref.');
    expect($exception)->toBeInstanceOf(DomainException::class)
        ->and($exception->getMessage())->toBe('Missing dashboard ref.');
});

it('creates dashboards with defaults and casts and validates required fields', function (): void {
    $action = app(CreateDashboard::class);

    $defaultPeriod = $action->handle([
        'team_id' => 10,
        'dashboard_ref' => 'CFO-EXEC',
        'name' => 'Executive Overview',
        'role' => 'cfo',
        'dimensions' => ['region' => 'EMEA'],
        'metadata' => ['theme' => 'light'],
    ]);

    expect($defaultPeriod)->toBeInstanceOf(Dashboard::class)
        ->and($defaultPeriod->period)->toBe('current')
        ->and($defaultPeriod->role)->toBe('cfo')
        ->and($defaultPeriod->dimensions)->toBe(['region' => 'EMEA'])
        ->and($defaultPeriod->metadata)->toBe(['theme' => 'light']);

    $customPeriod = $action->handle([
        'team_id' => 10,
        'dashboard_ref' => 'CONTROLLER-Q3',
        'name' => 'Quarterly Controller',
        'period' => '2026-Q3',
    ]);
    expect($customPeriod->period)->toBe('2026-Q3');
});

it('rejects missing required fields when creating a dashboard', function (array $override, string $expectedMessage): void {
    $base = [
        'team_id' => 1,
        'dashboard_ref' => 'DASH-1',
        'name' => 'Cash Dashboard',
    ];

    $payload = array_merge($base, $override);
    foreach ($override as $key => $val) {
        if ($val === null) {
            unset($payload[$key]);
        }
    }

    expect(fn () => app(CreateDashboard::class)->handle($payload))
        ->toThrow(InvalidDashboard::class, $expectedMessage);
})->with([
    'missing team_id' => [['team_id' => null], 'team_id is required.'],
    'blank dashboard_ref' => [['dashboard_ref' => ''], 'dashboard_ref is required.'],
    'blank name' => [['name' => '  '], 'name is required.'],
]);

it('upserts kpis and creates dashboard shares with relationships and casts', function (): void {
    Carbon::setTestNow('2026-08-29 09:15:00');

    $dashboard = app(CreateDashboard::class)->handle([
        'team_id' => 20,
        'dashboard_ref' => 'TREASURY',
        'name' => 'Treasury',
    ]);

    $upsertKpi = app(UpsertKpi::class);
    $kpi = $upsertKpi->handle($dashboard, [
        'kpi_ref' => 'cash_runway',
        'label' => 'Cash Runway',
        'value' => 14.5,
        'target' => 12.0,
        'unit' => 'months',
        'drill_through' => ['route' => 'cash.forecast'],
    ]);

    expect($kpi)->toBeInstanceOf(DashboardKpi::class)
        ->and($kpi->team_id)->toBe(20)
        ->and((float) $kpi->value)->toBe(14.5)
        ->and((float) $kpi->target)->toBe(12.0)
        ->and($kpi->unit)->toBe('months')
        ->and($kpi->refreshed_at?->toDateTimeString())->toBe('2026-08-29 09:15:00')
        ->and($kpi->drill_through)->toBe(['route' => 'cash.forecast'])
        ->and($kpi->dashboard->is($dashboard))->toBeTrue();

    // Upsert same kpi_ref updates existing record without duplicating.
    Carbon::setTestNow('2026-08-29 10:00:00');
    $updatedKpi = $upsertKpi->handle($dashboard, [
        'kpi_ref' => 'cash_runway',
        'label' => 'Cash Runway (Updated)',
        'value' => 16.25,
    ]);

    expect($updatedKpi->id)->toBe($kpi->id)
        ->and((float) $updatedKpi->value)->toBe(16.25)
        ->and($updatedKpi->refreshed_at?->toDateTimeString())->toBe('2026-08-29 10:00:00')
        ->and($dashboard->kpis()->count())->toBe(1);

    $expiresAt = Carbon::now()->addDays(7);
    $share = app(ShareDashboard::class)->handle($dashboard, [
        'shared_with_user_id' => 42,
        'shared_with_role' => 'auditor',
        'expires_at' => $expiresAt,
    ]);

    expect($share)->toBeInstanceOf(DashboardShare::class)
        ->and($share->team_id)->toBe(20)
        ->and($share->shared_with_user_id)->toBe(42)
        ->and($share->shared_with_role)->toBe('auditor')
        ->and(strlen($share->token))->toBe(48)
        ->and($share->expires_at?->toDateTimeString())->toBe($expiresAt->toDateTimeString())
        ->and($share->dashboard->is($dashboard))->toBeTrue();

    Carbon::setTestNow();
});

it('queries dashboards for team and finds individual dashboards safely', function (): void {
    $query = app(DashboardQuery::class);

    expect($query->forTeam(999))->toBeEmpty();

    $create = app(CreateDashboard::class);
    $d1 = $create->handle(['team_id' => 30, 'dashboard_ref' => 'D1', 'name' => 'One']);
    app(UpsertKpi::class)->handle($d1, ['kpi_ref' => 'ebitda', 'label' => 'EBITDA', 'value' => 50000]);
    app(ShareDashboard::class)->handle($d1, ['shared_with_role' => 'board']);

    $d2 = $create->handle(['team_id' => 31, 'dashboard_ref' => 'D2', 'name' => 'Other Team']);

    $list = $query->forTeam(30);
    expect($list)->toHaveCount(1)
        ->and($list->first()->id)->toBe($d1->id)
        ->and($list->first()->relationLoaded('kpis'))->toBeTrue()
        ->and($list->first()->relationLoaded('shares'))->toBeTrue();

    $found = $query->findForTeam(30, $d1->id);
    expect($found->id)->toBe($d1->id)
        ->and($found->relationLoaded('kpis'))->toBeTrue();

    // Cross-team lookup throws ModelNotFoundException.
    expect(fn () => $query->findForTeam(30, $d2->id))
        ->toThrow(ModelNotFoundException::class);
});
