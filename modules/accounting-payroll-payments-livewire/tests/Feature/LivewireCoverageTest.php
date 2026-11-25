<?php

declare(strict_types=1);

use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\PayrollPayments\Actions\TransitionPayrollPayment;
use Liberu\Accounting\PayrollPayments\Enums\PaymentStatus;
use Liberu\Accounting\PayrollPayments\Models\PayrollPaymentBatch;
use Liberu\Accounting\PayrollPaymentsLivewire\Livewire\PayrollPayments;
use Liberu\Accounting\PayrollPaymentsLivewire\PayrollPaymentsLivewireServiceProvider;

it('covers accounting-payroll-payments-livewire components', function (): void {
    $provider = $this->app->register(PayrollPaymentsLivewireServiceProvider::class, force: true);
    $provider->boot();
    $this->actingAs(User::factory()->create(['current_team_id' => 1]));

    $batch = PayrollPaymentBatch::query()->create([
        'team_id' => 1,
        'batch_ref' => 'BATCH-001',
        'currency' => 'GBP',
        'net_pay_amount' => 1000.00,
        'liability_amount' => 200.00,
        'status' => PaymentStatus::Draft,
    ]);

    $component = new PayrollPayments();
    $component->selectBatch((int) $batch->getKey());
    $component->status = 'approved';
    $component->transitionStatus(app(TransitionPayrollPayment::class));
    expect($batch->refresh()->status)->toBe(PaymentStatus::Approved);
    expect($component->render())->not->toBeNull();
});
