<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\ReimbursementsLivewire\Livewire\Reimbursements;
use Liberu\Accounting\ReimbursementsLivewire\ReimbursementsLivewireServiceProvider;
use Livewire\Livewire;

it('renders accounting-reimbursements-livewire livewire components', function (): void {
    $provider = $this->app->register(ReimbursementsLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) { $provider->boot(); }
    expect($provider)->toBeInstanceOf(ReimbursementsLivewireServiceProvider::class);

    expect(fn () => (new Reimbursements)->mount())->toThrow(Exception::class);

    $this->actingAs(User::factory()->create());

    $component = new Reimbursements;
    if (method_exists($component, 'mount')) { $component->mount(); }
    expect($component->render())->not->toBeNull();
});
