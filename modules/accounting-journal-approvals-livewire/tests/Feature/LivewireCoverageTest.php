<?php

declare(strict_types=1);

use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\JournalApprovals\Enums\ApprovalStatus;
use Liberu\Accounting\JournalApprovalsLivewire\JournalApprovalsLivewireServiceProvider;
use Liberu\Accounting\JournalApprovalsLivewire\Livewire\JournalApprovals;

it('renders accounting-journal-approvals-livewire livewire components', function (): void {
    $provider = $this->app->register(JournalApprovalsLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(JournalApprovalsLivewireServiceProvider::class);

    expect(fn () => (new JournalApprovals())->mount())->toThrow(Exception::class);

    $this->actingAs(User::factory()->create());

    $component = new JournalApprovals();
    if (method_exists($component, 'mount')) {
        $component->mount();
    }
    expect($component->render())->not->toBeNull();
    $component->status = ApprovalStatus::cases()[0]->value;
    if (method_exists($component, 'updated'.ucfirst('status'))) {
        $component->{'updated'.ucfirst('status')}();
    }
    expect($component->render())->not->toBeNull();
});
