<?php

declare(strict_types=1);

use Liberu\Accounting\BankAccountsLivewire\BankAccountsLivewireServiceProvider;
use Liberu\Accounting\BankAccountsLivewire\Livewire\Accounts;
use Liberu\Accounting\Erp\Models\User;

it('renders accounting-bank-accounts-livewire livewire components', function (): void {
    $provider = $this->app->register(BankAccountsLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(BankAccountsLivewireServiceProvider::class);

    expect(fn () => (new Accounts())->mount())->toThrow(Exception::class);

    $this->actingAs(User::factory()->create());

    $component = new Accounts();
    if (method_exists($component, 'mount')) {
        $component->mount();
    }
    expect($component->render())->not->toBeNull();
});
