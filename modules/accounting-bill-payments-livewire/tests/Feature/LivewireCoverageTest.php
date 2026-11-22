<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Liberu\Accounting\BillPaymentsLivewire\BillPaymentsLivewireServiceProvider;
use Liberu\Accounting\BillPaymentsLivewire\Livewire\Proposals;
use Liberu\Accounting\Erp\Models\User;
use Livewire\Livewire;

it('renders accounting-bill-payments-livewire livewire components', function (): void {
    $provider = $this->app->register(BillPaymentsLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) { $provider->boot(); }
    expect($provider)->toBeInstanceOf(BillPaymentsLivewireServiceProvider::class);

    expect(fn () => (new Proposals)->mount())->toThrow(Exception::class);

    $this->actingAs(User::factory()->create());

    $component = new Proposals;
    if (method_exists($component, 'mount')) { $component->mount(); }
    expect($component->render())->not->toBeNull();
});
