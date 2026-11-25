<?php

declare(strict_types=1);

use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\RecurringTransactionsLivewire\Livewire\RecurringTemplates;
use Liberu\Accounting\RecurringTransactionsLivewire\RecurringTransactionsLivewireServiceProvider;

it('renders accounting-recurring-transactions-livewire livewire components', function (): void {
    $provider = $this->app->register(RecurringTransactionsLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(RecurringTransactionsLivewireServiceProvider::class);

    expect(fn () => (new RecurringTemplates())->mount())->toThrow(Exception::class);

    $this->actingAs(User::factory()->create());

    $component = new RecurringTemplates();
    if (method_exists($component, 'mount')) {
        $component->mount();
    }
    expect($component->render())->not->toBeNull();
});
