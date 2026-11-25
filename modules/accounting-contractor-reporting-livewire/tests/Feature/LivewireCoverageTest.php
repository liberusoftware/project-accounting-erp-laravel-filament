<?php

declare(strict_types=1);

use Liberu\Accounting\ContractorReportingLivewire\ContractorReportingLivewireServiceProvider;
use Liberu\Accounting\ContractorReportingLivewire\Livewire\ReportOverview;
use Liberu\Accounting\Erp\Models\User;

it('renders accounting-contractor-reporting-livewire livewire components', function (): void {
    $provider = $this->app->register(ContractorReportingLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(ContractorReportingLivewireServiceProvider::class);

    $this->actingAs(User::factory()->create());

    $component = new ReportOverview();
    if (method_exists($component, 'mount')) {
        $component->mount();
    }
    expect($component->render())->not->toBeNull();
});
