<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\YearEndLivewire\Livewire\YearEndCloses;
use Liberu\Accounting\YearEndLivewire\Livewire\YearEndOverview;
use Liberu\Accounting\YearEndLivewire\YearEndLivewireServiceProvider;
use Livewire\Livewire;

it('renders accounting-year-end-livewire livewire components', function (): void {
    $provider = $this->app->register(YearEndLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) { $provider->boot(); }
    expect($provider)->toBeInstanceOf(YearEndLivewireServiceProvider::class);

    expect(fn () => (new YearEndCloses)->mount())->toThrow(Exception::class);

    $this->actingAs(User::factory()->create());

    $component = new YearEndCloses;
    if (method_exists($component, 'mount')) { $component->mount(); }
    expect($component->render())->not->toBeNull();
    $component = new YearEndOverview;
    if (method_exists($component, 'mount')) { $component->mount(); }
    expect($component->render())->not->toBeNull();
});
