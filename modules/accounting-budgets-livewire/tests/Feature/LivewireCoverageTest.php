<?php

declare(strict_types=1);

use Liberu\Accounting\BudgetsLivewire\BudgetsLivewireServiceProvider;
use Liberu\Accounting\BudgetsLivewire\Livewire\Budgets;
use Liberu\Accounting\Erp\Models\User;

it('renders accounting-budgets-livewire livewire components', function (): void {
    $provider = $this->app->register(BudgetsLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(BudgetsLivewireServiceProvider::class);

    $this->actingAs(User::factory()->create());

    $component = new Budgets();
    if (method_exists($component, 'mount')) {
        $component->mount();
    }
    expect($component->render())->not->toBeNull();
});
