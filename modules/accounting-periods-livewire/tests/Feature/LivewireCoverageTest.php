<?php

declare(strict_types=1);

use Liberu\Accounting\Core\Models\Book;
use Liberu\Accounting\Core\Models\LegalEntity;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\Periods\Models\AccountingPeriod;
use Liberu\Accounting\PeriodsLivewire\AccountingPeriodsLivewireServiceProvider;
use Livewire\Livewire;

it('covers accounting-periods-livewire components', function (): void {
    $provider = $this->app->register(AccountingPeriodsLivewireServiceProvider::class, force: true);
    $provider->boot();
    $this->actingAs(User::factory()->create());

    $entity = LegalEntity::query()->create(['name' => 'Periods Entity', 'currency_code' => 'USD', 'accounting_basis' => 'accrual']);
    $book = Book::query()->create(['legal_entity_id' => $entity->getKey(), 'name' => 'Book 1', 'code' => 'B1', 'accounting_basis' => 'accrual', 'is_active' => true]);

    Livewire::test('module-accounting-periods-periods')
        ->set('bookId', $book->getKey())
        ->set('startsOn', '2026-01-01')
        ->set('endsOn', '2026-01-31')
        ->call('save')
        ->assertDispatched('period-created')
        ->assertOk();

    $period = AccountingPeriod::query()->firstOrFail();

    Livewire::test('module-accounting-periods-periods')
        ->set('state', 'soft_closed')
        ->set('reason', 'Month end')
        ->call('changeState', (int) $period->getKey())
        ->assertDispatched('period-transitioned')
        ->assertOk();
});
