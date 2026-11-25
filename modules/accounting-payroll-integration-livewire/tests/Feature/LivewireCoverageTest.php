<?php

declare(strict_types=1);

use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\PayrollIntegration\Models\PayrollImport;
use Liberu\Accounting\PayrollIntegrationLivewire\PayrollIntegrationLivewireServiceProvider;
use Livewire\Livewire;

it('covers accounting-payroll-integration-livewire components', function (): void {
    $provider = $this->app->register(PayrollIntegrationLivewireServiceProvider::class, force: true);
    $provider->boot();
    $this->actingAs(User::factory()->create(['current_team_id' => 1]));

    Livewire::test('module-accounting-payroll-integration-imports')
        ->set('provider', 'gusto')
        ->set('periodStart', '2026-01-01')
        ->set('periodEnd', '2026-01-31')
        ->set('runRef', 'RUN-001')
        ->set('currency', 'GBP')
        ->set('employeeRefs', 'EMP-1,EMP-2')
        ->call('import')
        ->assertDispatched('payroll-imported')
        ->assertOk();

    $import = PayrollImport::query()->firstOrFail();

    Livewire::test('module-accounting-payroll-integration-imports')
        ->call('selectImport', (int) $import->getKey())
        ->set('status', 'validated')
        ->call('mark')
        ->assertDispatched('payroll-import-status-changed')
        ->assertOk();
});
