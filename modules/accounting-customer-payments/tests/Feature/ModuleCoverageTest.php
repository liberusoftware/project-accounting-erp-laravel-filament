<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Liberu\Accounting\CustomerPayments\Actions\AllocateCustomerPayment;
use Liberu\Accounting\CustomerPayments\Actions\ReconcileCustomerPayment;
use Liberu\Accounting\CustomerPayments\Actions\RecordCustomerPayment;
use Liberu\Accounting\CustomerPayments\Actions\RefundCustomerPayment;
use Liberu\Accounting\CustomerPayments\CustomerPaymentsServiceProvider;
use Liberu\Accounting\CustomerPayments\Enums\CustomerPaymentKind;
use Liberu\Accounting\CustomerPayments\Enums\CustomerPaymentStatus;
use Liberu\Accounting\CustomerPayments\Exceptions\InvalidCustomerPayment;
use Liberu\Accounting\CustomerPayments\Models\CustomerPayment;
use Liberu\Accounting\CustomerPayments\Models\CustomerPaymentAllocation;
use Liberu\Accounting\CustomerPayments\Queries\CustomerPaymentQuery;

it('registers and boots the customer payments service provider and migrations', function (): void {
    $provider = app()->getProvider(CustomerPaymentsServiceProvider::class)
        ?? new CustomerPaymentsServiceProvider(app());

    $provider->register();
    $provider->boot();

    expect($provider)->toBeInstanceOf(CustomerPaymentsServiceProvider::class)
        ->and(Schema::hasTable('accounting_customer_payments'))->toBeTrue()
        ->and(Schema::hasTable('accounting_customer_payment_allocations'))->toBeTrue();
});

it('exposes customer payment enums and exception class', function (): void {
    expect(array_map(static fn (CustomerPaymentKind $k): string => $k->value, CustomerPaymentKind::cases()))
        ->toBe(['receipt', 'payment_link', 'deposit', 'refund'])
        ->and(array_map(static fn (CustomerPaymentStatus $s): string => $s->value, CustomerPaymentStatus::cases()))
        ->toBe(['unreconciled', 'partially_allocated', 'allocated', 'reconciled']);

    $exception = new InvalidCustomerPayment('Invalid payment.');
    expect($exception)->toBeInstanceOf(DomainException::class)
        ->and($exception->getMessage())->toBe('Invalid payment.');
});

it('records customer payments from enum or string kind and validates inputs and duplicates', function (): void {
    $action = app(RecordCustomerPayment::class);

    $receipt = $action->handle([
        'team_id' => 10,
        'customer_id' => 'cust-10',
        'kind' => CustomerPaymentKind::Receipt,
        'reference' => 'PAY-001',
        'currency' => 'USD',
        'amount' => 250.00,
        'gateway_reference' => 'gw_123',
        'metadata' => ['method' => 'card'],
    ]);

    expect($receipt)->toBeInstanceOf(CustomerPayment::class)
        ->and($receipt->kind)->toBe(CustomerPaymentKind::Receipt)
        ->and($receipt->status)->toBe(CustomerPaymentStatus::Unreconciled)
        ->and((float) $receipt->amount)->toBe(250.0)
        ->and((float) $receipt->allocated_amount)->toBe(0.0)
        ->and((float) $receipt->refunded_amount)->toBe(0.0)
        ->and($receipt->gateway_reference)->toBe('gw_123')
        ->and($receipt->metadata)->toBe(['method' => 'card']);

    // String kind and same reference under a different kind succeeds.
    $link = $action->handle([
        'team_id' => 10,
        'customer_id' => 'cust-10',
        'kind' => 'payment_link',
        'reference' => 'PAY-001',
        'currency' => 'USD',
        'amount' => 100.00,
    ]);
    expect($link->kind)->toBe(CustomerPaymentKind::PaymentLink);

    // Duplicate team_id + kind + reference throws.
    expect(fn () => $action->handle([
        'team_id' => 10,
        'customer_id' => 'cust-99',
        'kind' => 'receipt',
        'reference' => 'PAY-001',
        'currency' => 'USD',
        'amount' => 50,
    ]))->toThrow(InvalidCustomerPayment::class, 'Payment reference already exists.');
});

it('rejects invalid payment recording payloads', function (array $override, string $expectedMessage): void {
    $base = [
        'team_id' => 1,
        'customer_id' => 'cust-1',
        'kind' => 'deposit',
        'reference' => 'DEP-1',
        'currency' => 'GBP',
        'amount' => 120,
    ];

    $payload = array_merge($base, $override);
    foreach ($override as $key => $val) {
        if ($val === null) {
            unset($payload[$key]);
        }
    }

    expect(fn () => app(RecordCustomerPayment::class)->handle($payload))
        ->toThrow(InvalidCustomerPayment::class, $expectedMessage);
})->with([
    'missing kind' => [['kind' => null], 'A valid payment kind is required.'],
    'invalid kind' => [['kind' => 'crypto'], 'A valid payment kind is required.'],
    'missing team_id' => [['team_id' => null], 'team_id is required.'],
    'blank customer_id' => [['customer_id' => ''], 'customer_id is required.'],
    'blank reference' => [['reference' => ' '], 'reference is required.'],
    'blank currency' => [['currency' => ''], 'currency is required.'],
    'missing amount' => [['amount' => null], 'amount is required.'],
    'zero amount' => [['amount' => 0], 'Payment amount must be positive.'],
    'negative amount' => [['amount' => -10], 'Payment amount must be positive.'],
]);

it('allocates, refunds, and reconciles customer payments across all validation branches', function (): void {
    $payment = app(RecordCustomerPayment::class)->handle([
        'team_id' => 20,
        'customer_id' => 'cust-20',
        'kind' => CustomerPaymentKind::Receipt,
        'reference' => 'RCP-20',
        'currency' => 'EUR',
        'amount' => 100,
    ]);

    $allocate = app(AllocateCustomerPayment::class);
    $refund = app(RefundCustomerPayment::class);
    $reconcile = app(ReconcileCustomerPayment::class);

    // Refund validation
    expect(fn () => $refund->handle($payment, 0))
        ->toThrow(InvalidCustomerPayment::class, 'Refund exceeds the refundable balance.')
        ->and(fn () => $refund->handle($payment, 101))
        ->toThrow(InvalidCustomerPayment::class);

    // Partial refund of 20 leaves 80 allocatable/refundable.
    $payment = $refund->handle($payment, 20);
    expect((float) $payment->refunded_amount)->toBe(20.0);

    // Allocation validation
    expect(fn () => $allocate->handle($payment, '', 10))
        ->toThrow(InvalidCustomerPayment::class, 'Allocation exceeds the unapplied payment balance.')
        ->and(fn () => $allocate->handle($payment, 'INV-1', 0))
        ->toThrow(InvalidCustomerPayment::class)
        ->and(fn () => $allocate->handle($payment, 'INV-1', 81))
        ->toThrow(InvalidCustomerPayment::class);

    // Partial allocation -> PartiallyAllocated
    $alloc1 = $allocate->handle($payment, 'INV-1', 30);
    $payment->refresh();

    expect($alloc1)->toBeInstanceOf(CustomerPaymentAllocation::class)
        ->and($alloc1->payment->is($payment))->toBeTrue()
        ->and((float) $alloc1->amount)->toBe(30.0)
        ->and($payment->status)->toBe(CustomerPaymentStatus::PartiallyAllocated)
        ->and((float) $payment->allocated_amount)->toBe(30.0);

    // Allocate exact remaining unrefunded amount (50) on a non-refunded payment to test full Allocated status.
    $fullPayment = app(RecordCustomerPayment::class)->handle([
        'team_id' => 20,
        'customer_id' => 'cust-20',
        'kind' => CustomerPaymentKind::Deposit,
        'reference' => 'DEP-20',
        'currency' => 'EUR',
        'amount' => 50,
    ]);
    $allocate->handle($fullPayment, 'INV-2', 50);
    expect($fullPayment->fresh()->status)->toBe(CustomerPaymentStatus::Allocated);

    // Reconcile validation & happy path
    expect(fn () => $reconcile->handle($payment, ' '))
        ->toThrow(InvalidCustomerPayment::class, 'A valid deposit reference is required and reconciled payments cannot be changed.');

    $reconciled = $reconcile->handle($payment, 'BANK-DEP-77');
    expect($reconciled->status)->toBe(CustomerPaymentStatus::Reconciled)
        ->and($reconciled->deposit_reference)->toBe('BANK-DEP-77')
        ->and($reconciled->relationLoaded('allocations'))->toBeTrue();

    expect(fn () => $reconcile->handle($reconciled, 'BANK-DEP-78'))
        ->toThrow(InvalidCustomerPayment::class);
});

it('queries customer payments by team and by customer', function (): void {
    $query = app(CustomerPaymentQuery::class);

    expect($query->forTeam(500))->toBeEmpty()
        ->and($query->forCustomer(500, 'cust-x'))->toBeEmpty();

    $record = app(RecordCustomerPayment::class);
    $allocate = app(AllocateCustomerPayment::class);

    $p1 = $record->handle([
        'team_id' => 50,
        'customer_id' => 'cust-a',
        'kind' => 'receipt',
        'reference' => 'R-1',
        'currency' => 'GBP',
        'amount' => 100,
    ]);
    $allocate->handle($p1, 'INV-10', 40);

    $p2 = $record->handle([
        'team_id' => 50,
        'customer_id' => 'cust-b',
        'kind' => 'receipt',
        'reference' => 'R-2',
        'currency' => 'GBP',
        'amount' => 60,
    ]);

    $record->handle([
        'team_id' => 51,
        'customer_id' => 'cust-a',
        'kind' => 'receipt',
        'reference' => 'R-3',
        'currency' => 'GBP',
        'amount' => 25,
    ]);

    $teamPayments = $query->forTeam(50);
    $custAPayments = $query->forCustomer(50, 'cust-a');

    expect($teamPayments)->toHaveCount(2)
        ->and($teamPayments->pluck('id')->all())->toContain($p1->id, $p2->id)
        ->and($teamPayments->every(fn (CustomerPayment $p): bool => $p->relationLoaded('allocations')))->toBeTrue()
        ->and($custAPayments)->toHaveCount(1)
        ->and($custAPayments->first()->id)->toBe($p1->id)
        ->and($custAPayments->first()->relationLoaded('allocations'))->toBeTrue();
});
