<?php

declare(strict_types=1);

use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\ManagementReportingLivewire\Livewire\ReportPacks;
use Liberu\Accounting\ManagementReportingLivewire\ManagementReportingLivewireServiceProvider;

it('renders accounting-management-reporting-livewire livewire components', function (): void {
    $provider = $this->app->register(ManagementReportingLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(ManagementReportingLivewireServiceProvider::class);

    expect(fn () => (new ReportPacks())->mount())->toThrow(Exception::class);

    $this->actingAs(User::factory()->create());

    $component = new ReportPacks();
    if (method_exists($component, 'mount')) {
        $component->mount();
    }
    expect($component->render())->not->toBeNull();
    $component->status = 'active';
    if (method_exists($component, 'updated'.ucfirst('status'))) {
        $component->{'updated'.ucfirst('status')}();
    }
});
