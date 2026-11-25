<?php

declare(strict_types=1);

use Liberu\Accounting\Core\Models\LegalEntity;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\FinancialMasterDataLivewire\FinancialMasterDataLivewireServiceProvider;
use Liberu\Accounting\FinancialMasterDataLivewire\Livewire\Parties;
use Liberu\Accounting\FinancialMasterDataLivewire\Livewire\ReferenceData;
use Livewire\Livewire;

it('covers accounting-financial-master-data-livewire components', function (): void {
    $provider = $this->app->register(FinancialMasterDataLivewireServiceProvider::class, force: true);
    $provider->boot();
    $this->actingAs(User::factory()->create());

    $entity = LegalEntity::query()->create(['name' => 'FMD Entity', 'currency_code' => 'USD', 'accounting_basis' => 'accrual']);

    Livewire::test(Parties::class)
        ->set('legalEntityId', (string) $entity->getKey())
        ->set('type', 'customer')
        ->set('name', 'Acme Customer')
        ->call('save')
        ->assertDispatched('party-created')
        ->assertOk();

    foreach (['items-services', 'tax-profiles', 'payment-terms'] as $res) {
        Livewire::test(ReferenceData::class)
            ->set('resource', $res)
            ->set('legalEntityId', (string) $entity->getKey())
            ->set('code', 'CODE-'.$res)
            ->set('name', 'Name '.$res)
            ->set('sku', 'SKU-'.$res)
            ->set('rate', '20')
            ->set('days', '30')
            ->call('save')
            ->assertDispatched('reference-data-created')
            ->assertOk();
    }
});
