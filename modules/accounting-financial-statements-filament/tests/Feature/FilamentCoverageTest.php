<?php

declare(strict_types=1);

use Filament\Panel;
use Illuminate\Auth\Access\AuthorizationException;
use Liberu\Accounting\Core\Models\Book;
use Liberu\Accounting\Core\Models\LegalEntity;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\FinancialStatementsFilament\FinancialStatementsFilamentPlugin;
use Liberu\Accounting\FinancialStatementsFilament\FinancialStatementsFilamentServiceProvider;
use Liberu\Accounting\FinancialStatementsFilament\Pages\FinancialStatements;

it('registers and configures accounting-financial-statements-filament filament resources', function (): void {
    $provider = $this->app->register(FinancialStatementsFilamentServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
    expect($provider)->toBeInstanceOf(FinancialStatementsFilamentServiceProvider::class);
    $plugin = FinancialStatementsFilamentPlugin::make();
    $panel = Panel::make()->id('test-accounting-financial-statements-filament');
    $plugin->register($panel);
    $plugin->boot($panel);
    expect($plugin->getId())->toBeString()->not->toBeEmpty();
});

it('covers FinancialStatements page mount and view data', function (): void {
    $page = new FinancialStatements();
    expect(fn () => $page->mount(1))->toThrow(AuthorizationException::class);

    $this->actingAs(User::factory()->create());
    $entity = LegalEntity::query()->create(['name' => 'FSF Ent', 'currency_code' => 'USD', 'accounting_basis' => 'accrual']);
    $book = Book::query()->create(['legal_entity_id' => $entity->getKey(), 'name' => 'FSF Book', 'code' => 'FSFB', 'accounting_basis' => 'accrual', 'is_active' => true]);

    $page->mount((int) $book->getKey());
    $data = Closure::bind(fn () => $this->getViewData(), $page, $page::class)();
    expect($data)->toHaveKey('statement');
});
