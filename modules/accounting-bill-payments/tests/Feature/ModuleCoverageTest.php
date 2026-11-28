<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Liberu\Accounting\BillPayments\Actions\ApproveBillPayment;
use Liberu\Accounting\BillPayments\Actions\CreateBillPaymentProposal;
use Liberu\Accounting\BillPayments\Actions\MarkBillPaymentPaid;
use Liberu\Accounting\BillPayments\Actions\RejectBillPayment;
use Liberu\Accounting\BillPayments\Actions\RequestBillPaymentApproval;
use Liberu\Accounting\BillPayments\Actions\SubmitBillPayment;
use Liberu\Accounting\BillPayments\BillPaymentsServiceProvider;
use Liberu\Accounting\BillPayments\Enums\BillPaymentStatus;
use Liberu\Accounting\BillPayments\Events\BillPaymentApproved;
use Liberu\Accounting\BillPayments\Events\BillPaymentSubmitted;
use Liberu\Accounting\BillPayments\Exceptions\InvalidBillPayment;
use Liberu\Accounting\BillPayments\Queries\BillPaymentOptimizationQuery;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Foundation\Integrations\Contracts\PaymentProviderAdapter;

it('boots bill payments service provider and covers enum, exception, and model helpers', function (): void {
    $provider = $this->app->register(BillPaymentsServiceProvider::class, force: true);
    $provider->boot();

    expect($provider)->toBeInstanceOf(BillPaymentsServiceProvider::class)
        ->and(array_column(BillPaymentStatus::cases(), 'value'))->toBe([
            'draft',
            'pending_approval',
            'approved',
            'submitted',
            'paid',
            'failed',
            'rejected',
        ])
        ->and((new InvalidBillPayment('Payment error'))->getMessage())->toBe('Payment error');
});

it('executes full bill payment approval, submission, paid lifecycle, and optimization query', function (): void {
    Event::fake([BillPaymentApproved::class, BillPaymentSubmitted::class]);

    $maker = User::factory()->create(['current_team_id' => 1]);
    $checker = User::factory()->create(['current_team_id' => 1]);
    $this->actingAs($maker);

    $proposal = app(CreateBillPaymentProposal::class)->handle([
        'team_id' => 1,
        'supplier_id' => 55,
        'bill_reference' => 'BILL-1001',
        'amount' => 1000.00,
        'currency' => 'gbp',
        'due_date' => '2026-06-30',
        'discount_date' => '2026-06-10',
        'discount_rate' => 2.5,
        'bank_details' => [
            'beneficiary_name' => 'Supplier Co',
            'iban' => 'GB29NWBK60161331926819',
        ],
        'payment_payload' => ['memo' => 'June bill'],
        'metadata' => ['batch' => 1],
    ]);

    expect($proposal->status)->toBe(BillPaymentStatus::Draft)
        ->and($proposal->currency)->toBe('GBP')
        ->and($proposal->approvalAmount())->toBe(1000.0)
        ->and($proposal->toArray())->not->toHaveKeys(['bank_details', 'payment_payload', 'provider_result']);

    $optimization = app(BillPaymentOptimizationQuery::class)->handle($proposal);
    expect($optimization)->toBe([
        'recommended_date' => '2026-06-10',
        'discount_amount' => 25.0,
        'rationale' => 'Pay by the discount date to capture the available discount.',
    ]);

    $pending = app(RequestBillPaymentApproval::class)->handle($proposal);
    expect($pending->status)->toBe(BillPaymentStatus::PendingApproval)
        ->and($pending->requested_by)->toBe($maker->getKey());

    // Maker cannot approve own proposal
    expect(fn () => app(ApproveBillPayment::class)->handle($pending))
        ->toThrow(InvalidBillPayment::class, 'different checker');

    $this->actingAs($checker);
    $approved = app(ApproveBillPayment::class)->handle($pending);
    expect($approved->status)->toBe(BillPaymentStatus::Approved)
        ->and($approved->approved_by)->toBe($checker->getKey())
        ->and($approved->approved_at)->not->toBeNull();

    Event::assertDispatched(BillPaymentApproved::class, fn (BillPaymentApproved $e): bool => $e->payment->is($approved));

    // Idempotent approve
    expect(app(ApproveBillPayment::class)->handle($approved)->status)->toBe(BillPaymentStatus::Approved);
    Event::assertDispatchedTimes(BillPaymentApproved::class, 1);

    $adapter = new class() implements PaymentProviderAdapter
    {
        public function sendPayment(array $payment): array
        {
            return ['id' => 'pay_ext_123', 'status' => 'accepted', 'received' => $payment['reference']];
        }

        public function sendBulkPayments(string $title, array $payments, ?string $scheduleFor = null): array
        {
            return [];
        }
    };

    $submitted = app(SubmitBillPayment::class)->handle($approved, $adapter);
    expect($submitted->status)->toBe(BillPaymentStatus::Submitted)
        ->and($submitted->submitted_at)->not->toBeNull()
        ->and($submitted->provider_reference)->toBe('pay_ext_123')
        ->and($submitted->provider_result['received'])->toBe('BILL-1001');

    Event::assertDispatched(BillPaymentSubmitted::class, fn (BillPaymentSubmitted $e): bool => $e->payment->is($submitted));

    // Idempotent submit when already submitted
    expect(app(SubmitBillPayment::class)->handle($submitted, $adapter)->status)->toBe(BillPaymentStatus::Submitted);
    Event::assertDispatchedTimes(BillPaymentSubmitted::class, 1);

    $paid = app(MarkBillPaymentPaid::class)->handle($submitted);
    expect($paid->status)->toBe(BillPaymentStatus::Paid)
        ->and($paid->paid_at)->not->toBeNull();

    // Idempotent submit when already paid
    expect(app(SubmitBillPayment::class)->handle($paid, $adapter)->status)->toBe(BillPaymentStatus::Paid);
});

it('handles rejection, optimization fallback, provider failure, and validation errors', function (): void {
    $create = app(CreateBillPaymentProposal::class);

    $valid = [
        'team_id' => 2,
        'supplier_id' => 88,
        'bill_reference' => 'BILL-2001',
        'amount' => 500,
        'currency' => 'USD',
        'due_date' => '2026-07-15',
        'bank_details' => ['beneficiary_name' => 'Vendor', 'account_number' => '987654'],
    ];

    expect(fn () => $create->handle([...$valid, 'supplier_id' => null]))->toThrow(InvalidBillPayment::class)
        ->and(fn () => $create->handle([...$valid, 'bill_reference' => '']))->toThrow(InvalidBillPayment::class)
        ->and(fn () => $create->handle([...$valid, 'amount' => 0]))->toThrow(InvalidBillPayment::class)
        ->and(fn () => $create->handle([...$valid, 'currency' => 'US']))->toThrow(InvalidBillPayment::class)
        ->and(fn () => $create->handle([...$valid, 'due_date' => '']))->toThrow(InvalidBillPayment::class)
        ->and(fn () => $create->handle([...$valid, 'bank_details' => 'not-array']))->toThrow(InvalidBillPayment::class)
        ->and(fn () => $create->handle([...$valid, 'bank_details' => ['iban' => 'DE123']]))->toThrow(InvalidBillPayment::class)
        ->and(fn () => $create->handle([...$valid, 'bank_details' => ['beneficiary_name' => 'Vendor']]))->toThrow(InvalidBillPayment::class);

    $proposal = $create->handle($valid);
    expect(fn () => $create->handle($valid))->toThrow(InvalidBillPayment::class, 'already exists');

    // Optimization without discount date
    $noDiscount = app(BillPaymentOptimizationQuery::class)->handle($proposal);
    expect($noDiscount)->toBe([
        'recommended_date' => '2026-07-15',
        'discount_amount' => 0.0,
        'rationale' => 'Pay by the contractual due date.',
    ]);

    // Cannot approve/reject/submit/mark-paid from Draft
    expect(fn () => app(ApproveBillPayment::class)->handle($proposal))->toThrow(InvalidBillPayment::class)
        ->and(fn () => app(RejectBillPayment::class)->handle($proposal, 'reason'))->toThrow(InvalidBillPayment::class)
        ->and(fn () => app(MarkBillPaymentPaid::class)->handle($proposal))->toThrow(InvalidBillPayment::class);

    $pending = app(RequestBillPaymentApproval::class)->handle($proposal);
    expect(fn () => app(RequestBillPaymentApproval::class)->handle($pending))->toThrow(InvalidBillPayment::class, 'Only draft')
        ->and(fn () => app(RejectBillPayment::class)->handle($pending, ''))->toThrow(InvalidBillPayment::class);

    $rejected = app(RejectBillPayment::class)->handle($pending, 'Incorrect amount');
    expect($rejected->status)->toBe(BillPaymentStatus::Rejected)
        ->and($rejected->rejected_at)->not->toBeNull()
        ->and($rejected->failure_message)->toBe('Incorrect amount');

    $failingAdapter = new class() implements PaymentProviderAdapter
    {
        public function sendPayment(array $payment): array
        {
            throw new RuntimeException('Gateway timeout');
        }

        public function sendBulkPayments(string $title, array $payments, ?string $scheduleFor = null): array
        {
            return [];
        }
    };

    expect(fn () => app(SubmitBillPayment::class)->handle($rejected, $failingAdapter))
        ->toThrow(InvalidBillPayment::class, 'Only approved');

    $approved = $create->handle([...$valid, 'bill_reference' => 'BILL-2002']);
    $approved = app(RequestBillPaymentApproval::class)->handle($approved);
    $approved = app(ApproveBillPayment::class)->handle($approved);

    expect(fn () => app(SubmitBillPayment::class)->handle($approved, $failingAdapter))
        ->toThrow(RuntimeException::class, 'Gateway timeout');
});
