<?php

declare(strict_types=1);

use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\OperationalReportsLivewire\Livewire\ReportRuns;
use Liberu\Accounting\OperationalReportsLivewire\OperationalReportsLivewireServiceProvider;
use Livewire\Livewire;

it('renders accounting-operational-reports-livewire livewire components', function (): void {
    $provider = $this->app->register(OperationalReportsLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(OperationalReportsLivewireServiceProvider::class);

    expect(fn () => (new ReportRuns())->mount())->toThrow(Exception::class);

    $this->actingAs(User::factory()->create());

    $test = Livewire::test(ReportRuns::class)->assertOk();
});
