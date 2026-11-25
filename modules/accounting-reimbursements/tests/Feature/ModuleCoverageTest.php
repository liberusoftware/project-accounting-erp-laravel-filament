<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Liberu\Accounting\Reimbursements\Actions\CreatePaymentBatch;
use Liberu\Accounting\Reimbursements\Actions\CreateReimbursementLiability;
use Liberu\Accounting\Reimbursements\Actions\ReconcilePaymentBatch;
use Liberu\Accounting\Reimbursements\Actions\UpdatePaymentProviderStatus;
use Liberu\Accounting\Reimbursements\Enums\BatchStatus;
use Liberu\Accounting\Reimbursements\Enums\ReimbursementStatus;
use Liberu\Accounting\Reimbursements\Exceptions\InvalidReimbursement;
use Liberu\Accounting\Reimbursements\Models\ReimbursementBatch;
use Liberu\Accounting\Reimbursements\Models\ReimbursementLiability;
use Liberu\Accounting\Reimbursements\Models\ReimbursementReconciliation;
use Liberu\Accounting\Reimbursements\Models\ReimbursementRemittance;
use Liberu\Accounting\Reimbursements\ReimbursementsServiceProvider;

it('registers the reimbursements service provider and runs migrations', function (): void {
    expect(app()->getProvider(ReimbursementsServiceProvider::class))
        ->toBeInstanceOf(ReimbursementsServiceProvider::class);

    foreach ([
        'accounting_reimbursement_batches',
        'accounting_reimbursement_liabilities',
        'accounting_reimbursement_remittances',
        'accounting_reimbursement_reconciliations',
    ] as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }

    expect(Schema::hasColumns('accounting_reimbursement_batches', [
        'id', 'team_id', 'currency', 'total_amount', 'status', 'provider', 'provider_ref', 'exported_at', 'submitted_at', 'paid_at', 'failure_message', 'metadata',
    ]))->toBeTrue();
});

it('exposes all reimbursement enum cases', function (): void {
    expect(array_column(BatchStatus::cases(), 'value'))
        ->toBe(['draft', 'exported', 'submitted', 'paid', 'failed', 'reconciled']);

    expect(array_column(ReimbursementStatus::cases(), 'value'))
        ->toBe(['approved', 'batched', 'submitted', 'paid', 'failed', 'reconciled', 'cancelled']);
});

it('creates approved reimbursement liabilities and validates inputs', function (): void {
    $createLiability = new CreateReimbursementLiability();

    $liability = $createLiability->handle([
        'team_id' => 14,
        'payee_ref' => 'emp:101',
        'source_type' => 'expense_claim',
        'source_id' => 'EC-55',
        'kind' => 'mileage',
        'currency' => 'usd',
        'amount' => 145.678,
        'metadata' => ['region' => 'US'],
    ]);

    expect($liability)->toBeInstanceOf(ReimbursementLiability::class)
        ->and($liability->currency)->toBe('USD')
        ->and($liability->amount)->toBe('145.68')
        ->and($liability->kind)->toBe('mileage')
        ->and($liability->status)->toBe(ReimbursementStatus::Approved)
        ->and($liability->approved_at)->not->toBeNull()
        ->and($liability->metadata)->toBe(['region' => 'US']);

    foreach ([
        ['payee_ref' => '', 'amount' => 10, 'currency' => 'USD'],
        ['payee_ref' => 'emp:1', 'amount' => 0, 'currency' => 'USD'],
        ['payee_ref' => 'emp:1', 'amount' => -5, 'currency' => 'USD'],
        ['payee_ref' => 'emp:1', 'amount' => 10, 'currency' => ''],
    ] as $bad) {
        expect(fn () => $createLiability->handle($bad))
            ->toThrow(InvalidReimbursement::class, 'An approved liability requires payee, positive amount, and currency.');
    }
});

it('batches approved liabilities, enforces single currency and team scope, and rejects invalid states', function (): void {
    $createLiability = new CreateReimbursementLiability();
    $createBatch = new CreatePaymentBatch();

    expect(fn () => $createBatch->handle([]))
        ->toThrow(InvalidReimbursement::class, 'Only approved liabilities can be batched.');

    $usd1 = $createLiability->handle(['team_id' => 1, 'payee_ref' => 'emp:1', 'currency' => 'USD', 'amount' => 50]);
    $usd2 = $createLiability->handle(['team_id' => 1, 'payee_ref' => 'emp:2', 'currency' => 'USD', 'amount' => 75.25]);
    $eur = $createLiability->handle(['team_id' => 1, 'payee_ref' => 'emp:3', 'currency' => 'EUR', 'amount' => 20]);
    $otherTeam = $createLiability->handle(['team_id' => 2, 'payee_ref' => 'emp:4', 'currency' => 'USD', 'amount' => 30]);

    expect(fn () => $createBatch->handle([$usd1->id, $eur->id]))
        ->toThrow(InvalidReimbursement::class, 'A payment batch cannot mix currencies.');

    // Team filter excludes otherTeam, so batch only includes usd1 and usd2
    $batch = $createBatch->handle([$usd1->id, $usd2->id, $otherTeam->id], 1);

    expect($batch)->toBeInstanceOf(ReimbursementBatch::class)
        ->and($batch->currency)->toBe('USD')
        ->and($batch->total_amount)->toBe('125.25')
        ->and($batch->status)->toBe(BatchStatus::Draft)
        ->and($batch->liabilities)->toHaveCount(2)
        ->and($usd1->fresh()->status)->toBe(ReimbursementStatus::Batched)
        ->and($otherTeam->fresh()->status)->toBe(ReimbursementStatus::Approved);

    // Already-batched liability cannot be batched again
    expect(fn () => $createBatch->handle([$usd1->id]))
        ->toThrow(InvalidReimbursement::class, 'Only approved liabilities can be batched.');

    $remittance = ReimbursementRemittance::create([
        'batch_id' => $batch->id,
        'payee_ref' => 'emp:1',
        'amount' => 50,
        'currency' => 'USD',
        'status' => 'sent',
        'document_ref' => 'REM-1',
        'sent_at' => now(),
        'metadata' => ['channel' => 'email'],
    ]);
    expect($remittance->amount)->toBe('50.00')
        ->and($remittance->metadata)->toBe(['channel' => 'email']);
});

it('updates payment provider statuses and reconciles payment batches', function (): void {
    $createLiability = new CreateReimbursementLiability();
    $createBatch = new CreatePaymentBatch();
    $updateStatus = new UpdatePaymentProviderStatus();
    $reconcile = new ReconcilePaymentBatch();

    $l1 = $createLiability->handle(['team_id' => 4, 'payee_ref' => 'emp:10', 'currency' => 'GBP', 'amount' => 200]);
    $batch = $createBatch->handle([$l1->id]);

    expect(fn () => $updateStatus->handle($batch, ['status' => 'unknown']))
        ->toThrow(InvalidReimbursement::class, 'Unknown provider status.');

    $exported = $updateStatus->handle($batch, ['status' => 'exported', 'provider' => 'wise']);
    expect($exported->status)->toBe(BatchStatus::Exported)
        ->and($l1->fresh()->status)->toBe(ReimbursementStatus::Submitted);

    $submitted = $updateStatus->handle($exported, ['status' => 'submitted', 'provider_ref' => 'WISE-99']);
    expect($submitted->status)->toBe(BatchStatus::Submitted)
        ->and($submitted->submitted_at)->not->toBeNull()
        ->and($submitted->provider_ref)->toBe('WISE-99');

    $failed = $updateStatus->handle($submitted, ['status' => 'failed']);
    expect($failed->status)->toBe(BatchStatus::Failed)
        ->and($failed->failure_message)->toBe('Provider reported failure.')
        ->and($l1->fresh()->status)->toBe(ReimbursementStatus::Failed);

    $failedCustom = $updateStatus->handle($failed, ['status' => 'failed', 'failure_message' => 'Insufficient funds']);
    expect($failedCustom->failure_message)->toBe('Insufficient funds');

    $paid = $updateStatus->handle($failedCustom, ['status' => 'paid']);
    expect($paid->status)->toBe(BatchStatus::Paid)
        ->and($paid->paid_at)->not->toBeNull()
        ->and($paid->failure_message)->toBeNull()
        ->and($l1->fresh()->status)->toBe(ReimbursementStatus::Paid);

    $reconciledStatus = $updateStatus->handle($paid, ['status' => 'reconciled']);
    expect($l1->fresh()->status)->toBe(ReimbursementStatus::Reconciled);

    // Reconciliation mismatch throws
    expect(fn () => $reconcile->handle($reconciledStatus, 195.00, 'STMT-1'))
        ->toThrow(InvalidReimbursement::class, 'Settled amount does not reconcile with the payment batch.');

    // Exact match reconciles
    $reconciliation = $reconcile->handle($reconciledStatus, 200.00, 'STMT-2');
    expect($reconciliation)->toBeInstanceOf(ReimbursementReconciliation::class)
        ->and($reconciliation->expected_amount)->toBe('200.00')
        ->and($reconciliation->settled_amount)->toBe('200.00')
        ->and($reconciliation->variance)->toBe('0.00')
        ->and($reconciliation->status)->toBe('matched')
        ->and($reconciliation->external_ref)->toBe('STMT-2')
        ->and($reconciledStatus->fresh()->status)->toBe(BatchStatus::Reconciled);
});
