<?php

declare(strict_types=1);

use Liberu\Accounting\Core\Models\Book;
use Liberu\Accounting\Core\Models\LegalEntity;
use Liberu\Accounting\CoreLivewire\AccountingCoreLivewireServiceProvider;
use Liberu\Accounting\CoreLivewire\Livewire\AccountingSettings;
use Liberu\Accounting\CoreLivewire\Livewire\Books;
use Liberu\Accounting\CoreLivewire\Livewire\LegalEntities;
use Liberu\Accounting\Erp\Models\User;
use Livewire\Livewire;

it('covers accounting-core-livewire components', function (): void {
    $provider = $this->app->register(AccountingCoreLivewireServiceProvider::class, force: true);
    $provider->boot();
    $this->actingAs(User::factory()->create());

    Livewire::test(LegalEntities::class)
        ->set('name', 'Core Entity')
        ->set('currencyCode', 'USD')
        ->set('accountingBasis', 'accrual')
        ->call('save')
        ->assertDispatched('legal-entity-created')
        ->assertOk();

    $entity = LegalEntity::query()->firstOrFail();

    Livewire::test(Books::class)
        ->set('legalEntityId', $entity->getKey())
        ->set('name', 'Main Book')
        ->set('code', 'MAIN')
        ->set('accountingBasis', 'accrual')
        ->call('save')
        ->assertDispatched('book-created')
        ->assertOk();

    $book = Book::query()->firstOrFail();

    Livewire::test(AccountingSettings::class)
        ->set('bookId', $book->getKey())
        ->set('setting', 'defaults')
        ->set('key', 'rounding')
        ->set('value', '{"scale":2}')
        ->call('save')
        ->assertDispatched('accounting-setting-saved')
        ->set('setting', 'policies')
        ->set('key', 'cutoff')
        ->set('value', '{"days":5}')
        ->call('save')
        ->assertDispatched('accounting-setting-saved')
        ->assertOk();
});
