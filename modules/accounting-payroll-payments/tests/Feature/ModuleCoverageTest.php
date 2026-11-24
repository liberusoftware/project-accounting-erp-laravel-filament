<?php

declare(strict_types=1);

use Liberu\Accounting\PayrollPayments\Actions\CreatePayrollPaymentBatch;
use Liberu\Accounting\PayrollPayments\Actions\TransitionPayrollPayment;
use Liberu\Accounting\PayrollPayments\Enums\PaymentStatus;
use Liberu\Accounting\PayrollPayments\Exceptions\InvalidPayrollPayment;
use Liberu\Accounting\PayrollPayments\Models\PayrollPaymentBatch;
use Liberu\Accounting\PayrollPayments\PayrollPaymentsServiceProvider;
use Liberu\Accounting\PayrollPayments\Queries\PayrollPaymentSummary;

it('registers and boots the payroll payments service provider', function (): void {
    $provider = $this->app->register(PayrollPaymentsServiceProvider::class, force: true);
    $provider->boot();

    expect($provider)->toBeInstanceOf(PayrollPaymentsServiceProvider::class);
});

it('exposes all payroll payment status enum cases', function (): void {
    expect(array_map(fn (PaymentStatus $s): string => $s->value, PaymentStatus::cases()))
        ->toBe(['draft', 'approved', 'submitted', 'settled', 'failed', 'reconciled']);
});

it('creates payment batches and transitions through valid and invalid states', function (): void {
    $create = new CreatePayrollPaymentBatch();
    $transition = new TransitionPayrollPayment();

    expect(fn () => $create->handle(['batch_ref' => '', 'net_pay_amount' => 100]))
        ->toThrow(InvalidPayrollPayment::class, 'Batch reference and positive non-negative amounts are required.');

    expect(fn () => $create->handle(['batch_ref' => 'PB-1', 'net_pay_amount' => -1, 'liability_amount' => 100]))
        ->toThrow(InvalidPayrollPayment::class);

    expect(fn () => $create->handle(['batch_ref' => 'PB-1', 'net_pay_amount' => 0, 'liability_amount' => 0]))
        ->toThrow(InvalidPayrollPayment::class);

    $batch = $create->handle([
        'team_id' => 21,
        'batch_ref' => 'PB-2026-01',
        'net_pay_ref' => 'NP-1',
        'liability_ref' => 'LB-1',
        'currency' => 'GBP',
        'net_pay_amount' => 2500.00,
        'liability_amount' => 750.00,
        'provider' => 'bacs',
        'metadata' => ['cycle' => 'monthly'],
    ]);

    expect($batch)->toBeInstanceOf(PayrollPaymentBatch::class)
        ->and($batch->status)->toBe(PaymentStatus::Draft)
        ->and($batch->totalAmount())->toBe(3250.0);

    // Invalid jump from Draft to Settled
    expect(fn () => $transition->handle($batch, PaymentStatus::Settled))
        ->toThrow(InvalidPayrollPayment::class, 'Invalid payroll payment transition.');

    // Fail from Draft, then recover to Approved -> Submitted -> Settled -> Reconciled
    $failed = $transition->handle($batch, PaymentStatus::Failed, [
        'failure_code' => 'BANK_REJECT',
        'failure_message' => 'Invalid sort code',
    ]);
    expect($failed->status)->toBe(PaymentStatus::Failed)
        ->and($failed->failure_code)->toBe('BANK_REJECT')
        ->and($failed->failure_message)->toBe('Invalid sort code');

    $approved = $transition->handle($failed, PaymentStatus::Approved);
    expect($approved->status)->toBe(PaymentStatus::Approved)
        ->and($approved->approved_at)->not->toBeNull();

    $submitted = $transition->handle($approved, PaymentStatus::Submitted);
    expect($submitted->status)->toBe(PaymentStatus::Submitted)
        ->and($submitted->submitted_at)->not->toBeNull();

    $settled = $transition->handle($submitted, PaymentStatus::Settled);
    expect($settled->status)->toBe(PaymentStatus::Settled)
        ->and($settled->settled_at)->not->toBeNull();

    $reconciled = $transition->handle($settled, PaymentStatus::Reconciled);
    expect($reconciled->status)->toBe(PaymentStatus::Reconciled)
        ->and($reconciled->reconciled_at)->not->toBeNull();

    // Terminal state cannot transition
    expect(fn () => $transition->handle($reconciled, PaymentStatus::Approved))
        ->toThrow(InvalidPayrollPayment::class);
});

it('summarizes payroll payment batches for a team', function (): void {
    $summary = new PayrollPaymentSummary();
    expect($summary->forTeam(88))->toBe([
        'count' => 0,
        'total_amount' => 0.0,
        'pending' => 0,
        'failed' => 0,
        'reconciled' => 0,
    ]);

    $create = new CreatePayrollPaymentBatch();
    $transition = new TransitionPayrollPayment();

    $b1 = $create->handle(['team_id' => 30, 'batch_ref' => 'B1', 'net_pay_amount' => 100, 'liability_amount' => 50]);
    $b2 = $create->handle(['team_id' => 30, 'batch_ref' => 'B2', 'net_pay_amount' => 200, 'liability_amount' => 100]);
    $b3 = $create->handle(['team_id' => 30, 'batch_ref' => 'B3', 'net_pay_amount' => 300, 'liability_amount' => 0]);

    $transition->handle($b1, PaymentStatus::Approved);
    $transition->handle($b2, PaymentStatus::Failed);
    $b3 = $transition->handle($b3, PaymentStatus::Approved);
    $b3 = $transition->handle($b3, PaymentStatus::Submitted);
    $b3 = $transition->handle($b3, PaymentStatus::Settled);
    $transition->handle($b3, PaymentStatus::Reconciled);

    expect($summary->forTeam(30))->toBe([
        'count' => 3,
        'total_amount' => 750.0,
        'pending' => 1,
        'failed' => 1,
        'reconciled' => 1,
    ]);
});
