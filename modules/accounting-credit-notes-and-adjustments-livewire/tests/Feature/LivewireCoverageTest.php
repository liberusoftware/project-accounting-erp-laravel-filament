<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Liberu\Accounting\CreditNotesAndAdjustmentsLivewire\CreditNotesAndAdjustmentsLivewireServiceProvider;
use Liberu\Accounting\CreditNotesAndAdjustmentsLivewire\Livewire\CreditNoteOverview;
use Liberu\Accounting\Erp\Models\User;
use Livewire\Livewire;

it('renders accounting-credit-notes-and-adjustments-livewire livewire components', function (): void {
    $provider = $this->app->register(CreditNotesAndAdjustmentsLivewireServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) { $provider->boot(); }
    expect($provider)->toBeInstanceOf(CreditNotesAndAdjustmentsLivewireServiceProvider::class);


    $this->actingAs(User::factory()->create());

    $component = new CreditNoteOverview;
    if (method_exists($component, 'mount')) { $component->mount(); }
    expect($component->render())->not->toBeNull();
});
