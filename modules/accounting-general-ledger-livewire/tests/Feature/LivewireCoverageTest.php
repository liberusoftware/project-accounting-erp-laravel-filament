<?php

declare(strict_types=1);

use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\GeneralLedgerLivewire\GeneralLedgerLivewireServiceProvider;
use Livewire\Livewire;

it('covers accounting-general-ledger-livewire components', function (): void {
    $provider = $this->app->register(GeneralLedgerLivewireServiceProvider::class, force: true);
    $provider->boot();
    $this->actingAs(User::factory()->create());

    Livewire::test('accounting-general-ledger-journals', ['bookId' => 1])->assertOk();
});
