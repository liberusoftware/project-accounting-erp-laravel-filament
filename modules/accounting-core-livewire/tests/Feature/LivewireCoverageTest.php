<?php

declare(strict_types=1);

use Liberu\Accounting\Core\Models\Book;
use Liberu\Accounting\Core\Models\LegalEntity;
use Liberu\Accounting\CoreLivewire\AccountingCoreLivewireServiceProvider;
use Liberu\Accounting\Erp\Models\User;
use Livewire\Livewire;

it('covers accounting-core-livewire components', function (): void {
    $provider = $this->app->register(AccountingCoreLivewireServiceProvider::class, force: true);
    $provider->boot();
    $this->actingAs(User::factory()->create());

    Livewire::test('module-accounting-core-legal-entities')
        ->set('name', 'Core Entity')
        ->set('currencyCode', 'USD')
        ->set('accountingBasis', 'accrual')
        ->call('save')
        ->assertDispatched('legal-entity-created')
        ->assertOk();

    $entity = LegalEntity::query()->firstOrFail();

    Livewire::test('module-accounting-core-books')
        ->set('legalEntityId', $entity->getKey())
        ->set('name', 'Main Book')
        ->set('code', 'MAIN')
        ->set('accountingBasis', 'accrual')
        ->call('save')
        ->assertDispatched('book-created')
        ->assertOk();

    $book = Book::query()->firstOrFail();

    Livewire::test('module-accounting-core-settings')
        ->set('bookId', $book->getKey())
        ->set('setting', 'defaults')
        ->set('key', 'rounding')
        ->set('value', '{"scale":2}')
        ->call('save')
        ->assertDispatched('accounting-setting-saved')
        ->set('setting', 'policies')
        ->assertOk();
});
