<?php

declare(strict_types=1);

use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\GoodsAndServiceReceiptsLivewire\GoodsAndServiceReceiptsLivewireServiceProvider;
use Liberu\Accounting\GoodsAndServiceReceiptsLivewire\Livewire\Receipts;

it('renders accounting-goods-and-service-receipts-livewire livewire components', function (): void {
    $provider = $this->app->register(GoodsAndServiceReceiptsLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(GoodsAndServiceReceiptsLivewireServiceProvider::class);

    expect(fn () => (new Receipts())->mount())->toThrow(Exception::class);

    $this->actingAs(User::factory()->create());

    $component = new Receipts();
    if (method_exists($component, 'mount')) {
        $component->mount();
    }
    expect($component->render())->not->toBeNull();
    $component->status = 'active';
    if (method_exists($component, 'updated'.ucfirst('status'))) {
        $component->{'updated'.ucfirst('status')}();
    }
});
