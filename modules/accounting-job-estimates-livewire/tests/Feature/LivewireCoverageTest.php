<?php

declare(strict_types=1);

use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\JobEstimates\Enums\EstimateStatus;
use Liberu\Accounting\JobEstimatesLivewire\JobEstimatesLivewireServiceProvider;
use Liberu\Accounting\JobEstimatesLivewire\Livewire\JobEstimates;

it('renders accounting-job-estimates-livewire livewire components', function (): void {
    $provider = $this->app->register(JobEstimatesLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(JobEstimatesLivewireServiceProvider::class);

    expect(fn () => (new JobEstimates())->mount())->toThrow(Exception::class);

    $this->actingAs(User::factory()->create());

    $component = new JobEstimates();
    if (method_exists($component, 'mount')) {
        $component->mount();
    }
    expect($component->render())->not->toBeNull();
    $component->status = EstimateStatus::cases()[0]->value;
    if (method_exists($component, 'updated'.ucfirst('status'))) {
        $component->{'updated'.ucfirst('status')}();
    }
    expect($component->render())->not->toBeNull();
});
