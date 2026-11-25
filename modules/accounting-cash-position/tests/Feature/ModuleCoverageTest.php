<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Liberu\Accounting\CashPosition\Actions\CreateCashPosition;
use Liberu\Accounting\CashPosition\Actions\RefreshCashPosition;
use Liberu\Accounting\CashPosition\CashPositionServiceProvider;
use Liberu\Accounting\CashPosition\Exceptions\InvalidCashPosition;
use Liberu\Accounting\CashPosition\Models\CashPosition;
use Liberu\Accounting\CashPosition\Queries\CashPositionQuery;

it('boots the cash position service provider and schema', function (): void {
    $provider = app()->getProvider(CashPositionServiceProvider::class);
    expect($provider)->toBeInstanceOf(CashPositionServiceProvider::class);
    $provider->boot();

    expect(Schema::hasTable('accounting_cash_positions'))->toBeTrue();

    $exception = new InvalidCashPosition('Invalid position');
    expect($exception)->toBeInstanceOf(DomainException::class)
        ->and($exception->getMessage())->toBe('Invalid position');
});

it('creates a cash position and validates required fields, currency, and balances', function (): void {
    $create = new CreateCashPosition();

    foreach (['team_id', 'view_ref', 'currency'] as $field) {
        $payload = ['team_id' => 1, 'view_ref' => 'TREASURY-MAIN', 'currency' => 'USD'];
        unset($payload[$field]);

        expect(fn () => $create->handle($payload))
            ->toThrow(InvalidCashPosition::class, "{$field} is required.");
    }

    expect(fn () => $create->handle(['team_id' => 1, 'view_ref' => 'V1', 'currency' => 'gbp']))
        ->toThrow(InvalidCashPosition::class, 'Currency must be a three-letter uppercase code.');

    foreach (['ledger_balance', 'available_balance', 'outstanding_receipts', 'outstanding_payments', 'committed_cash'] as $balanceField) {
        expect(fn () => $create->handle([
            'team_id' => 1,
            'view_ref' => 'V1',
            'currency' => 'USD',
            $balanceField => -0.01,
        ]))->toThrow(InvalidCashPosition::class, "{$balanceField} cannot be negative.");
    }

    $position = $create->handle([
        'team_id' => 1,
        'view_ref' => 'GROUP-USD',
        'entity_ref' => 'ENT-1',
        'currency' => 'USD',
        'ledger_balance' => 120000.50,
        'available_balance' => 110000.25,
        'outstanding_receipts' => 15000,
        'outstanding_payments' => 5000,
        'committed_cash' => 20000,
    ]);

    expect($position)->toBeInstanceOf(CashPosition::class)
        ->and($position->ledger_balance)->toBe('120000.50000000')
        ->and($position->available_balance)->toBe('110000.25000000')
        ->and($position->refreshed_at)->toBeInstanceOf(Carbon::class);
});

it('refreshes a cash position and rejects negative balances', function (): void {
    $position = (new CreateCashPosition())->handle([
        'team_id' => 2,
        'view_ref' => 'DAILY',
        'currency' => 'EUR',
        'ledger_balance' => 5000,
    ]);

    $refresh = new RefreshCashPosition();

    foreach (['ledger_balance', 'available_balance', 'outstanding_receipts', 'outstanding_payments', 'committed_cash'] as $balanceField) {
        expect(fn () => $refresh->handle($position, [$balanceField => -1]))
            ->toThrow(InvalidCashPosition::class, "{$balanceField} cannot be negative.");
    }

    $refreshed = $refresh->handle($position, [
        'ledger_balance' => 9500.75,
        'available_balance' => 9000.00,
        'outstanding_receipts' => 1200,
        'outstanding_payments' => 400,
        'committed_cash' => 500,
    ]);

    expect($refreshed->ledger_balance)->toBe('9500.75000000')
        ->and($refreshed->available_balance)->toBe('9000.00000000')
        ->and($refreshed->outstanding_receipts)->toBe('1200.00000000')
        ->and($refreshed->outstanding_payments)->toBe('400.00000000')
        ->and($refreshed->committed_cash)->toBe('500.00000000');
});

it('queries cash positions by team', function (): void {
    $query = new CashPositionQuery();
    expect($query->forTeam(888))->toBeEmpty();

    $create = new CreateCashPosition();
    $first = $create->handle(['team_id' => 6, 'view_ref' => 'V-1', 'currency' => 'USD']);
    $second = $create->handle(['team_id' => 6, 'view_ref' => 'V-2', 'currency' => 'EUR']);
    $create->handle(['team_id' => 7, 'view_ref' => 'V-3', 'currency' => 'GBP']);

    $results = $query->forTeam(6);
    expect($results)->toHaveCount(2)
        ->and($results->pluck('id')->all())->toBe([$second->id, $first->id]);
});
