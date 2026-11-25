<?php

declare(strict_types=1);

use Liberu\Accounting\Core\Models\Book;
use Liberu\Accounting\Core\Models\LegalEntity;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\PoliciesLivewire\AccountingPoliciesLivewireServiceProvider;
use Livewire\Livewire;

it('covers accounting-policies-livewire components', function (): void {
    $provider = $this->app->register(AccountingPoliciesLivewireServiceProvider::class, force: true);
    $provider->boot();
    $this->actingAs(User::factory()->create());

    $entity = LegalEntity::query()->create(['name' => 'Pol Entity', 'currency_code' => 'USD', 'accounting_basis' => 'accrual']);
    $book = Book::query()->create(['legal_entity_id' => $entity->getKey(), 'name' => 'Book P', 'code' => 'BP', 'accounting_basis' => 'accrual', 'is_active' => true]);

    Livewire::test('module-accounting-policies-policy-rules')
        ->set('bookId', (string) $book->getKey())
        ->set('category', 'recognition')
        ->set('key', 'rev_rec')
        ->set('value', '{"method":"straight_line"}')
        ->set('effectiveFrom', '2026-01-01')
        ->call('save')
        ->assertDispatched('policy-rule-saved')
        ->assertOk();
});
