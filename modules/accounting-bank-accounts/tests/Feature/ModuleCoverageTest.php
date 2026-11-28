<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Liberu\Accounting\BankAccounts\Actions\CreateBankAccount;
use Liberu\Accounting\BankAccounts\Actions\SetBankAccountStatus;
use Liberu\Accounting\BankAccounts\Actions\UpdateBankAccount;
use Liberu\Accounting\BankAccounts\BankAccountsServiceProvider;
use Liberu\Accounting\BankAccounts\Enums\BankAccountStatus;
use Liberu\Accounting\BankAccounts\Enums\BankAccountType;
use Liberu\Accounting\BankAccounts\Events\BankAccountCreated;
use Liberu\Accounting\BankAccounts\Events\BankAccountStatusChanged;
use Liberu\Accounting\BankAccounts\Exceptions\InvalidBankAccount;
use Liberu\Accounting\BankAccounts\Queries\BankAccountBalanceQuery;
use Liberu\Accounting\Core\Models\LegalEntity;

function createBankAccountsEntity(string $name = 'Acme Corp', string $currency = 'USD'): LegalEntity
{
    return LegalEntity::query()->create([
        'name' => $name,
        'currency_code' => $currency,
        'accounting_basis' => 'accrual',
        'is_active' => true,
    ]);
}

it('boots bank accounts service provider and covers enums, events, and exceptions', function (): void {
    $provider = $this->app->register(BankAccountsServiceProvider::class, force: true);
    $provider->boot();

    expect($provider)->toBeInstanceOf(BankAccountsServiceProvider::class)
        ->and(array_column(BankAccountStatus::cases(), 'value'))->toBe(['active', 'inactive', 'closed'])
        ->and(array_column(BankAccountType::cases(), 'value'))->toBe(['bank', 'current', 'savings', 'credit', 'loan', 'cash']);

    $exception = new InvalidBankAccount('Invalid account state');
    expect($exception)->toBeInstanceOf(DomainException::class)
        ->and($exception->getMessage())->toBe('Invalid account state');
});

it('creates, updates, queries, and transitions bank accounts', function (): void {
    Event::fake([BankAccountCreated::class, BankAccountStatusChanged::class]);

    $entity = createBankAccountsEntity('Alpha Ltd', 'GBP');
    $otherEntity = createBankAccountsEntity('Beta Ltd', 'USD');

    $empty = app(BankAccountBalanceQuery::class)->handle($entity->getKey());
    expect($empty['total'])->toBe(0.0)
        ->and($empty['accounts'])->toHaveCount(0);

    $create = app(CreateBankAccount::class);
    $account = $create->handle([
        'legal_entity_id' => $entity->getKey(),
        'owner_type' => 'team',
        'owner_id' => '1',
        'name' => 'Operating Account',
        'institution_name' => 'Barclays',
        'account_type' => BankAccountType::Current->value,
        'currency' => 'gbp',
        'opening_balance' => 1250.50,
        'opening_date' => '2026-01-01',
        'account_number' => '12345678',
        'routing_number' => '20-00-00',
        'feed_reference' => 'feed-op-1',
        'metadata' => ['primary' => true],
    ]);

    expect($account->currency)->toBe('GBP')
        ->and($account->status)->toBe(BankAccountStatus::Active)
        ->and($account->account_type)->toBe(BankAccountType::Current)
        ->and((float) $account->opening_balance)->toBe(1250.50)
        ->and((float) $account->current_balance)->toBe(1250.50)
        ->and($account->account_number)->toBe('12345678')
        ->and($account->routing_number)->toBe('20-00-00')
        ->and($account->metadata)->toBe(['primary' => true])
        ->and($account->opening_date->toDateString())->toBe('2026-01-01')
        ->and($account->legalEntity->is($entity))->toBeTrue()
        ->and($account->toArray())->not->toHaveKeys(['account_number', 'routing_number']);

    Event::assertDispatched(BankAccountCreated::class, fn (BankAccountCreated $e): bool => $e->account->is($account));

    $second = $create->handle([
        'legal_entity_id' => $entity->getKey(),
        'name' => 'Reserve Savings',
        'account_type' => BankAccountType::Savings->value,
        'currency' => 'gbp',
        'opening_balance' => 500,
        'opening_date' => '2026-01-02',
    ]);

    $otherEntityAccount = $create->handle([
        'legal_entity_id' => $otherEntity->getKey(),
        'name' => 'Operating Account',
        'account_type' => BankAccountType::Bank->value,
        'currency' => 'usd',
        'opening_balance' => 300,
        'opening_date' => '2026-01-03',
    ]);

    $updated = app(UpdateBankAccount::class)->handle($account, [
        'institution_name' => 'HSBC',
        'currency' => 'eur',
        'current_balance' => 0,
    ]);

    expect($updated->institution_name)->toBe('HSBC')
        ->and($updated->currency)->toBe('EUR')
        ->and((float) $updated->current_balance)->toBe(0.0);

    $setStatus = app(SetBankAccountStatus::class);
    $inactive = $setStatus->handle($updated, BankAccountStatus::Inactive);
    expect($inactive->status)->toBe(BankAccountStatus::Inactive)
        ->and($inactive->closed_at)->toBeNull();

    Event::assertDispatched(BankAccountStatusChanged::class, fn (BankAccountStatusChanged $e): bool => $e->account->is($inactive) && $e->previousStatus === 'active');

    $closed = $setStatus->handle($inactive, BankAccountStatus::Closed);
    expect($closed->status)->toBe(BankAccountStatus::Closed)
        ->and($closed->closed_at)->not->toBeNull();

    $stillClosed = $setStatus->handle($closed, BankAccountStatus::Closed);
    expect($stillClosed->status)->toBe(BankAccountStatus::Closed);

    $entityQuery = app(BankAccountBalanceQuery::class)->handle($entity->getKey());
    expect($entityQuery['total'])->toBe(500.0)
        ->and($entityQuery['accounts']->pluck('id')->all())->toBe([$second->getKey()]);

    $allQuery = app(BankAccountBalanceQuery::class)->handle();
    expect($allQuery['total'])->toBe(800.0)
        ->and($allQuery['accounts']->pluck('id')->all())->toBe([$otherEntityAccount->getKey(), $second->getKey()]);
});

it('validates bank account creation, updates, and status transitions', function (): void {
    $entity = createBankAccountsEntity('Gamma Ltd');
    $create = app(CreateBankAccount::class);

    $valid = [
        'legal_entity_id' => $entity->getKey(),
        'name' => 'Cash Drawer',
        'account_type' => BankAccountType::Cash->value,
        'currency' => 'USD',
        'opening_balance' => 100,
        'opening_date' => '2026-02-01',
    ];

    expect(fn () => $create->handle([...$valid, 'legal_entity_id' => 999999]))->toThrow(InvalidBankAccount::class)
        ->and(fn () => $create->handle([...$valid, 'name' => '   ']))->toThrow(InvalidBankAccount::class)
        ->and(fn () => $create->handle([...$valid, 'account_type' => 'crypto']))->toThrow(InvalidBankAccount::class)
        ->and(fn () => $create->handle([...$valid, 'currency' => 'US']))->toThrow(InvalidBankAccount::class)
        ->and(fn () => $create->handle([...$valid, 'opening_balance' => -1]))->toThrow(InvalidBankAccount::class)
        ->and(fn () => $create->handle([...$valid, 'opening_date' => null]))->toThrow(InvalidBankAccount::class);

    $account = $create->handle($valid);

    expect(fn () => $create->handle($valid))->toThrow(InvalidBankAccount::class, 'already exists');

    $update = app(UpdateBankAccount::class);
    expect(fn () => $update->handle($account, ['currency' => 'US']))->toThrow(InvalidBankAccount::class, 'three-letter');

    $setStatus = app(SetBankAccountStatus::class);
    expect(fn () => $setStatus->handle($account, BankAccountStatus::Closed))->toThrow(InvalidBankAccount::class, 'zero balance');

    $update->handle($account, ['current_balance' => 0]);
    $closed = $setStatus->handle($account, BankAccountStatus::Closed);

    expect(fn () => $setStatus->handle($closed, BankAccountStatus::Active))->toThrow(InvalidBankAccount::class, 'cannot be reopened')
        ->and(fn () => $update->handle($closed, ['name' => 'Renamed']))->toThrow(InvalidBankAccount::class, 'cannot be changed');
});
