<?php

declare(strict_types=1);

use Liberu\Accounting\AnomalyDetectionLivewire\AnomalyDetectionLivewireServiceProvider;
use Liberu\Accounting\AnomalyDetectionLivewire\Livewire\Anomalies;
use Liberu\Accounting\Erp\Models\User;

it('renders accounting-anomaly-detection-livewire livewire components', function (): void {
    $provider = $this->app->register(AnomalyDetectionLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(AnomalyDetectionLivewireServiceProvider::class);

    $this->actingAs(User::factory()->create());

    $component = new Anomalies();
    if (method_exists($component, 'mount')) {
        $component->mount();
    }
    expect($component->render())->not->toBeNull();
});
