<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Liberu\Accounting\AccountsPayableLivewire\AccountsPayableLivewireServiceProvider;
use Liberu\Accounting\AccountsPayableLivewire\Livewire\Aging;
use Liberu\Accounting\AccountsPayableLivewire\Livewire\Payables;
use Liberu\Accounting\AccountsPayableLivewire\Livewire\Reconciliation;
use Liberu\Accounting\Erp\Models\User;
use Livewire\Livewire;

it('covers accounting-accounts-payable-livewire components', function (): void {
    $provider = $this->app->register(AccountsPayableLivewireServiceProvider::class, force: true);
    $provider->boot();

    expect(fn () => (new Aging())->mount())->toThrow(AuthorizationException::class);
    expect(fn () => (new Payables())->mount(1))->toThrow(AuthorizationException::class);
    expect(fn () => (new Reconciliation())->mount())->toThrow(AuthorizationException::class);

    $this->actingAs(User::factory()->create());

    expect(fn () => (new Payables())->mount(0))->toThrow(ValidationException::class);

    Livewire::test('module-accounting-accounts-payable-aging')->assertOk();
    Livewire::test('module-accounting-accounts-payable-payables', ['partyId' => 1])->assertOk();
    Livewire::test('module-accounting-accounts-payable-reconciliation')->assertOk();
});
