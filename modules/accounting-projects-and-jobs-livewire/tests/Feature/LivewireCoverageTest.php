<?php

declare(strict_types=1);

use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\ProjectsAndJobsLivewire\Livewire\ProjectJobs;
use Liberu\Accounting\ProjectsAndJobsLivewire\ProjectsAndJobsLivewireServiceProvider;

it('renders accounting-projects-and-jobs-livewire livewire components', function (): void {
    $provider = $this->app->register(ProjectsAndJobsLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(ProjectsAndJobsLivewireServiceProvider::class);

    expect(fn () => (new ProjectJobs())->mount())->toThrow(Exception::class);

    $this->actingAs(User::factory()->create());

    $component = new ProjectJobs();
    if (method_exists($component, 'mount')) {
        $component->mount();
    }
    expect($component->render())->not->toBeNull();
});
