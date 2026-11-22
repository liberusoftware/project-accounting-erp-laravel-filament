<?php

declare(strict_types=1);

use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\RevenueRecognitionLivewire\Livewire\RevenueSchedules;
use Liberu\Accounting\RevenueRecognitionLivewire\RevenueRecognitionLivewireServiceProvider;

it('renders accounting-revenue-recognition-livewire livewire components', function (): void {
    $provider = $this->app->register(RevenueRecognitionLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(RevenueRecognitionLivewireServiceProvider::class);

    expect(fn () => (new RevenueSchedules())->mount())->toThrow(Exception::class);

    $this->actingAs(User::factory()->create());

    $component = new RevenueSchedules();
    if (method_exists($component, 'mount')) {
        $component->mount();
    }
    expect($component->render())->not->toBeNull();
});
