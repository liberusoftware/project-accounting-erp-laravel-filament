<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Liberu\Accounting\AccountsReceivableLivewire\AccountsReceivableLivewireServiceProvider;
use Liberu\Accounting\AccountsReceivableLivewire\Livewire\Aging;
use Liberu\Accounting\AccountsReceivableLivewire\Livewire\Receivables;
use Liberu\Accounting\AccountsReceivableLivewire\Livewire\Statement;
use Liberu\Accounting\Erp\Models\User;
use Livewire\Livewire;

it('covers accounting-accounts-receivable-livewire components', function (): void {
    $provider = $this->app->register(AccountsReceivableLivewireServiceProvider::class, force: true);
    $provider->boot();

    expect(fn () => (new Aging())->mount())->toThrow(AuthorizationException::class);
    expect(fn () => (new Receivables())->mount(1))->toThrow(AuthorizationException::class);
    expect(fn () => (new Statement())->mount(1))->toThrow(AuthorizationException::class);

    $this->actingAs(User::factory()->create());

    Livewire::test(Aging::class)->assertOk();
    Livewire::test(Receivables::class, ['partyId' => 1])->assertOk();
    Livewire::test(Statement::class, ['partyId' => 1])
        ->set('from', '2026-01-01')
        ->set('to', '2026-12-31')
        ->assertOk();
});
