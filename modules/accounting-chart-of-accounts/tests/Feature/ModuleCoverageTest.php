<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Liberu\Accounting\ChartOfAccounts\Actions\ArchiveAccount;
use Liberu\Accounting\ChartOfAccounts\Actions\SaveAccount;
use Liberu\Accounting\ChartOfAccounts\ChartOfAccountsServiceProvider;
use Liberu\Accounting\ChartOfAccounts\Enums\AccountType;
use Liberu\Accounting\ChartOfAccounts\Enums\NormalBalance;
use Liberu\Accounting\ChartOfAccounts\Events\AccountArchived;
use Liberu\Accounting\ChartOfAccounts\Events\AccountCreated;
use Liberu\Accounting\ChartOfAccounts\Exceptions\InvalidAccount;
use Liberu\Accounting\ChartOfAccounts\Exceptions\InvalidAccountHierarchy;
use Liberu\Accounting\ChartOfAccounts\Models\Account;
use Liberu\Accounting\Core\Models\LegalEntity;

function createCoaLegalEntity(string $name = 'Acme Ltd', string $reg = 'REG-COA-1'): LegalEntity
{
    return LegalEntity::query()->create([
        'name' => $name,
        'registration_number' => $reg,
        'currency_code' => 'USD',
        'accounting_basis' => 'accrual',
        'is_active' => true,
    ]);
}

it('boots the chart of accounts service provider and schema', function (): void {
    $provider = app()->getProvider(ChartOfAccountsServiceProvider::class);
    expect($provider)->toBeInstanceOf(ChartOfAccountsServiceProvider::class);
    $provider->boot();

    expect(Schema::hasTable('accounting_chart_accounts'))->toBeTrue();
});

it('maps account types to default normal balances and defines exceptions', function (): void {
    expect(AccountType::Asset->defaultNormalBalance())->toBe(NormalBalance::Debit)
        ->and(AccountType::Expense->defaultNormalBalance())->toBe(NormalBalance::Debit)
        ->and(AccountType::Liability->defaultNormalBalance())->toBe(NormalBalance::Credit)
        ->and(AccountType::Equity->defaultNormalBalance())->toBe(NormalBalance::Credit)
        ->and(AccountType::Revenue->defaultNormalBalance())->toBe(NormalBalance::Credit);

    expect(new InvalidAccount('Bad account'))->toBeInstanceOf(DomainException::class)
        ->and(new InvalidAccountHierarchy('Bad hierarchy'))->toBeInstanceOf(DomainException::class);
});

it('saves, updates, relates, and scopes accounts while dispatching AccountCreated once', function (): void {
    Event::fake([AccountCreated::class]);

    $entity = createCoaLegalEntity('Primary Co', 'REG-COA-PRIMARY');
    $otherEntity = createCoaLegalEntity('Other Co', 'REG-COA-OTHER');
    $saver = app(SaveAccount::class);

    $parent = $saver->handle([
        'legal_entity_id' => $entity->id,
        'code' => ' 1000 ',
        'name' => ' Current Assets ',
        'type' => 'asset',
        'metadata' => ['section' => 'balance_sheet'],
    ]);

    expect($parent->code)->toBe('1000')
        ->and($parent->name)->toBe('Current Assets')
        ->and($parent->type)->toBe(AccountType::Asset)
        ->and($parent->normal_balance)->toBe(NormalBalance::Debit)
        ->and($parent->is_control_account)->toBeFalse()
        ->and($parent->allow_manual_entry)->toBeTrue()
        ->and($parent->is_active)->toBeTrue()
        ->and($parent->metadata)->toBe(['section' => 'balance_sheet'])
        ->and($parent->legalEntity->is($entity))->toBeTrue();

    Event::assertDispatched(AccountCreated::class, fn (AccountCreated $e): bool => $e->account->is($parent));

    $child = $saver->handle([
        'legal_entity_id' => $entity->id,
        'parent_id' => $parent->id,
        'code' => '1010',
        'name' => 'Cash at Bank',
        'type' => 'asset',
        'normal_balance' => 'debit',
    ]);

    expect($child->parent->is($parent))->toBeTrue()
        ->and($parent->children()->count())->toBe(1);

    // Same code allowed on another legal entity
    $other = $saver->handle([
        'legal_entity_id' => $otherEntity->id,
        'code' => '1000',
        'name' => 'Other Assets',
        'type' => 'liability',
    ]);
    expect($other->normal_balance)->toBe(NormalBalance::Credit);

    // Update existing account does not dispatch AccountCreated
    Event::fake([AccountCreated::class]);
    $updated = $saver->handle(['name' => 'Cash and Equivalents'], $child);
    expect($updated->name)->toBe('Cash and Equivalents');
    Event::assertNotDispatched(AccountCreated::class);

    expect(Account::query()->active()->count())->toBe(3);
});

it('validates account attributes and hierarchy rules', function (): void {
    $entity = createCoaLegalEntity('Guard Co', 'REG-COA-GUARD');
    $otherEntity = createCoaLegalEntity('Foreign Co', 'REG-COA-FOREIGN');
    $saver = app(SaveAccount::class);

    expect(fn () => $saver->handle(['legal_entity_id' => $entity->id, 'code' => '', 'name' => 'X', 'type' => 'asset']))
        ->toThrow(InvalidAccount::class, 'An account requires a legal entity, code, name, and type.');
    expect(fn () => $saver->handle(['legal_entity_id' => $entity->id, 'code' => '100', 'name' => 'X', 'type' => 'unknown']))
        ->toThrow(InvalidAccount::class, 'The account type is not supported.');
    expect(fn () => $saver->handle(['legal_entity_id' => $entity->id, 'code' => '100', 'name' => 'X', 'type' => 'asset', 'normal_balance' => 'credit']))
        ->toThrow(InvalidAccount::class, 'The normal balance must match the account type.');

    $root = $saver->handle(['legal_entity_id' => $entity->id, 'code' => '2000', 'name' => 'Liabilities', 'type' => 'liability']);
    $mid = $saver->handle(['legal_entity_id' => $entity->id, 'parent_id' => $root->id, 'code' => '2100', 'name' => 'Payables', 'type' => 'liability']);
    $leaf = $saver->handle(['legal_entity_id' => $entity->id, 'parent_id' => $mid->id, 'code' => '2110', 'name' => 'Trade Payables', 'type' => 'liability']);
    $foreign = $saver->handle(['legal_entity_id' => $otherEntity->id, 'code' => '9000', 'name' => 'Foreign', 'type' => 'asset']);

    expect(fn () => $saver->handle(['legal_entity_id' => $entity->id, 'code' => '2000', 'name' => 'Duplicate', 'type' => 'liability']))
        ->toThrow(InvalidAccount::class, 'already in use');

    // Self parent
    expect(fn () => $saver->handle(['parent_id' => $root->id], $root))
        ->toThrow(InvalidAccountHierarchy::class, 'An account cannot be its own parent.');

    // Foreign parent
    expect(fn () => $saver->handle(['legal_entity_id' => $entity->id, 'parent_id' => $foreign->id, 'code' => '2200', 'name' => 'Bad Parent', 'type' => 'liability']))
        ->toThrow(InvalidAccountHierarchy::class, 'same legal entity');

    // Non-existent parent
    expect(fn () => $saver->handle(['legal_entity_id' => $entity->id, 'parent_id' => 999999, 'code' => '2201', 'name' => 'Missing Parent', 'type' => 'liability']))
        ->toThrow(InvalidAccountHierarchy::class);

    // Cycle: move root under its descendant leaf
    expect(fn () => $saver->handle(['parent_id' => $leaf->id], $root))
        ->toThrow(InvalidAccountHierarchy::class, 'descendants');
});

it('archives accounts, blocks archiving with active children or already archived, and rejects inactive parents', function (): void {
    Event::fake([AccountArchived::class]);

    $entity = createCoaLegalEntity('Archive Co', 'REG-COA-ARC');
    $saver = app(SaveAccount::class);
    $archiver = app(ArchiveAccount::class);

    $parent = $saver->handle(['legal_entity_id' => $entity->id, 'code' => '4000', 'name' => 'Revenue', 'type' => 'revenue']);
    $child = $saver->handle(['legal_entity_id' => $entity->id, 'parent_id' => $parent->id, 'code' => '4100', 'name' => 'Sales', 'type' => 'revenue']);

    expect(fn () => $archiver->handle($parent))
        ->toThrow(InvalidAccountHierarchy::class, 'active child accounts');

    $archivedChild = $archiver->handle($child);
    expect($archivedChild->is_active)->toBeFalse();
    Event::assertDispatched(AccountArchived::class, fn (AccountArchived $e): bool => $e->account->is($archivedChild));

    expect(fn () => $archiver->handle($archivedChild))
        ->toThrow(InvalidAccountHierarchy::class, 'already archived');

    // Now parent has no active children and can be archived
    $archivedParent = $archiver->handle($parent);
    expect($archivedParent->is_active)->toBeFalse()
        ->and(Account::query()->active()->count())->toBe(0);

    // Cannot attach new child under an archived parent
    expect(fn () => $saver->handle(['legal_entity_id' => $entity->id, 'parent_id' => $archivedParent->id, 'code' => '4200', 'name' => 'New', 'type' => 'revenue']))
        ->toThrow(InvalidAccountHierarchy::class, 'same legal entity');
});
