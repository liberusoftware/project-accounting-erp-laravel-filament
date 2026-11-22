<?php

declare(strict_types=1);

use Liberu\Accounting\ChartOfAccountsLivewire\ChartOfAccountsLivewireServiceProvider;
use Liberu\Accounting\ChartOfAccountsLivewire\Livewire\Accounts;
use Liberu\Accounting\Core\Models\LegalEntity;
use Liberu\Accounting\Erp\Models\User;
use Livewire\Livewire;

it('covers accounting-chart-of-accounts-livewire components', function (): void {
    $provider = $this->app->register(ChartOfAccountsLivewireServiceProvider::class, force: true);
    $provider->boot();
    $this->actingAs(User::factory()->create());

    $entity = LegalEntity::query()->create(['name' => 'Acme Ltd', 'currency_code' => 'USD', 'accounting_basis' => 'accrual']);

    Livewire::test(Accounts::class)
        ->set('legalEntityId', (string) $entity->getKey())
        ->set('code', '1000')
        ->set('name', 'Cash')
        ->set('type', 'asset')
        ->call('save')
        ->assertDispatched('account-created')
        ->assertOk();
});
