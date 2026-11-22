<?php

declare(strict_types=1);

use Liberu\Accounting\EmployeeExpensesLivewire\EmployeeExpensesLivewireServiceProvider;
use Liberu\Accounting\EmployeeExpensesLivewire\Livewire\Claims;
use Liberu\Accounting\Erp\Models\User;

it('renders accounting-employee-expenses-livewire livewire components', function (): void {
    $provider = $this->app->register(EmployeeExpensesLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(EmployeeExpensesLivewireServiceProvider::class);

    expect(fn () => (new Claims())->mount())->toThrow(Exception::class);

    $this->actingAs(User::factory()->create());

    $component = new Claims();
    if (method_exists($component, 'mount')) {
        $component->mount();
    }
    expect($component->render())->not->toBeNull();
    $component->status = 'active';
    if (method_exists($component, 'updated'.ucfirst('status'))) {
        $component->{'updated'.ucfirst('status')}();
    }
});
