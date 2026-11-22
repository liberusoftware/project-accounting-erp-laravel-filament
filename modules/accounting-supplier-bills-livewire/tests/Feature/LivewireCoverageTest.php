<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Liberu\Accounting\Core\Models\LegalEntity;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\FinancialMasterData\Models\Party;
use Liberu\Accounting\SupplierBills\Actions\CreateSupplierBill;
use Liberu\Accounting\SupplierBillsLivewire\Livewire\SupplierBills;
use Liberu\Accounting\SupplierBillsLivewire\SupplierBillsLivewireServiceProvider;
use Livewire\Livewire;

it('covers accounting-supplier-bills-livewire components', function (): void {
    $provider = $this->app->register(SupplierBillsLivewireServiceProvider::class, force: true);
    $provider->boot();

    expect(fn () => (new SupplierBills)->mount())->toThrow(Exception::class);
    expect(fn () => (new SupplierBills)->create([], [], app(CreateSupplierBill::class)))->toThrow(AuthorizationException::class);

    $this->actingAs(User::factory()->create(['current_team_id' => 1]));

    $entity = LegalEntity::query()->create(['name' => 'SB Entity', 'currency_code' => 'GBP', 'accounting_basis' => 'accrual']);
    $party = Party::query()->create(['legal_entity_id' => $entity->getKey(), 'type' => 'supplier', 'name' => 'Supplier A']);

    Livewire::test(SupplierBills::class, ['partyId' => (int) $party->getKey()])
        ->set('search', 'BILL')
        ->call('create', [
            'party_id' => (int) $party->getKey(),
            'bill_date' => '2026-01-15',
            'due_on' => '2026-02-15',
            'currency' => 'GBP',
        ], [
            ['description' => 'Hosting', 'quantity' => 1, 'unit_price' => 100],
        ])
        ->assertDispatched('supplier-bill-created')
        ->assertOk();
});
