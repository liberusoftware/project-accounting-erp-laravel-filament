<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Liberu\Accounting\Core\Models\Book;
use Liberu\Accounting\Core\Models\LegalEntity;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\FinancialStatements\Enums\StatementType;
use Liberu\Accounting\FinancialStatementsLivewire\FinancialStatementsLivewireServiceProvider;
use Liberu\Accounting\FinancialStatementsLivewire\Livewire\Statements;

it('covers accounting-financial-statements-livewire components', function (): void {
    $provider = $this->app->register(FinancialStatementsLivewireServiceProvider::class, force: true);
    $provider->boot();

    expect(fn () => (new Statements())->mount(1, '2026-01-01', '2026-12-31'))->toThrow(AuthorizationException::class);

    $this->actingAs(User::factory()->create());

    expect(fn () => (new Statements())->mount(1, 'bad-date', '2026-12-31'))->toThrow(InvalidArgumentException::class);
    expect(fn () => (new Statements())->mount(1, '2026-12-31', '2026-01-01'))->toThrow(InvalidArgumentException::class);

    $entity = LegalEntity::query()->create(['name' => 'FS Entity', 'currency_code' => 'USD', 'accounting_basis' => 'accrual']);
    $book = Book::query()->create(['legal_entity_id' => $entity->getKey(), 'name' => 'FS Book', 'code' => 'FSB', 'accounting_basis' => 'accrual', 'is_active' => true]);

    $component = new Statements();
    $component->mount((int) $book->getKey(), '2026-01-01', '2026-12-31');
    $component->updatedStatementType('invalid');
    expect($component->statementType)->toBe(StatementType::ProfitAndLoss->value);

    foreach (StatementType::cases() as $type) {
        $component->statementType = $type->value;
        expect($component->render())->not->toBeNull();
    }
});
