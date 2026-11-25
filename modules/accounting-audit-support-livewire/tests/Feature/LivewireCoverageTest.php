<?php

declare(strict_types=1);

use Liberu\Accounting\AuditSupportLivewire\AuditSupportLivewireServiceProvider;
use Liberu\Accounting\AuditSupportLivewire\Livewire\AuditRequests;
use Liberu\Accounting\Erp\Models\User;

it('renders accounting-audit-support-livewire livewire components', function (): void {
    $provider = $this->app->register(AuditSupportLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(AuditSupportLivewireServiceProvider::class);

    $this->actingAs(User::factory()->create());

    $component = new AuditRequests();
    if (method_exists($component, 'mount')) {
        $component->mount();
    }
    expect($component->render())->not->toBeNull();
});
