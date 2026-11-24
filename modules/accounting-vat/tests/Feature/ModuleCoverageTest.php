<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Liberu\Accounting\Vat\Actions\AddVatAdjustment;
use Liberu\Accounting\Vat\Actions\CreateVatReturn;
use Liberu\Accounting\Vat\Actions\RecordVatDigitalEvidence;
use Liberu\Accounting\Vat\Actions\RecordVatTransaction;
use Liberu\Accounting\Vat\Actions\SubmitVatReturn;
use Liberu\Accounting\Vat\Enums\VatDirection;
use Liberu\Accounting\Vat\Enums\VatRecordStatus;
use Liberu\Accounting\Vat\Enums\VatReturnStatus;
use Liberu\Accounting\Vat\Exceptions\InvalidVat;
use Liberu\Accounting\Vat\Models\VatAdjustment;
use Liberu\Accounting\Vat\Models\VatDigitalRecord;
use Liberu\Accounting\Vat\Models\VatRecord;
use Liberu\Accounting\Vat\Models\VatReturn;
use Liberu\Accounting\Vat\Queries\VatReturnBoxes;
use Liberu\Accounting\Vat\VatServiceProvider;

it('registers vat service provider and creates tables', function (): void {
    expect(app()->getProvider(VatServiceProvider::class))
        ->toBeInstanceOf(VatServiceProvider::class);
    expect(Schema::hasTable('accounting_vat_records'))->toBeTrue();
    expect(Schema::hasTable('accounting_vat_returns'))->toBeTrue();
    expect(Schema::hasTable('accounting_vat_adjustments'))->toBeTrue();
    expect(Schema::hasTable('accounting_vat_digital_records'))->toBeTrue();
});

it('exposes vat enums', function (): void {
    expect(array_map(static fn (VatDirection $c): string => $c->value, VatDirection::cases()))
        ->toBe(['input', 'output']);
    expect(array_map(static fn (VatRecordStatus $c): string => $c->value, VatRecordStatus::cases()))
        ->toBe(['draft', 'posted', 'adjusted']);
    expect(array_map(static fn (VatReturnStatus $c): string => $c->value, VatReturnStatus::cases()))
        ->toBe(['draft', 'submitted', 'accepted', 'rejected']);
});

it('records vat transactions with enum or string direction and validates inputs', function (): void {
    $recordAction = app(RecordVatTransaction::class);

    $output = $recordAction->handle([
        'team_id' => 1,
        'direction' => VatDirection::Output,
        'tax_code' => 'STD20',
        'net_amount' => 100,
        'tax_amount' => 20,
        'tax_rate' => 20,
        'reverse_charge' => false,
        'box' => 1,
        'occurred_on' => '2026-01-15',
        'evidence' => 'INV-1',
        'metadata' => ['country' => 'GB'],
    ]);

    expect($output)->toBeInstanceOf(VatRecord::class)
        ->and($output->direction)->toBe(VatDirection::Output)
        ->and($output->status)->toBe(VatRecordStatus::Draft)
        ->and($output->scheme)->toBe('standard')
        ->and($output->net_amount)->toBe('100.000000')
        ->and($output->tax_amount)->toBe('20.000000')
        ->and($output->tax_rate)->toBe('20.000000')
        ->and($output->reverse_charge)->toBeFalse()
        ->and($output->occurred_on->toDateString())->toBe('2026-01-15')
        ->and($output->metadata)->toBe(['country' => 'GB']);

    $input = $recordAction->handle([
        'team_id' => 1,
        'direction' => 'input',
        'tax_code' => 'RC',
        'net_amount' => 50,
        'tax_amount' => 10,
        'reverse_charge' => true,
        'scheme' => 'cash',
        'status' => VatRecordStatus::Posted,
        'occurred_on' => '2026-01-16',
    ]);

    expect($input->direction)->toBe(VatDirection::Input)
        ->and($input->status)->toBe(VatRecordStatus::Posted)
        ->and($input->scheme)->toBe('cash')
        ->and($input->reverse_charge)->toBeTrue();

    $invalidMissing = [
        ['team_id' => 1, 'direction' => 'invalid', 'tax_code' => 'STD', 'net_amount' => 10, 'tax_amount' => 2, 'occurred_on' => '2026-01-01'],
        ['team_id' => 1, 'tax_code' => 'STD', 'net_amount' => 10, 'tax_amount' => 2, 'occurred_on' => '2026-01-01'],
        ['team_id' => 1, 'direction' => 'output', 'net_amount' => 10, 'tax_amount' => 2, 'occurred_on' => '2026-01-01'],
        ['team_id' => 1, 'direction' => 'output', 'tax_code' => 'STD', 'net_amount' => 10, 'tax_amount' => 2],
    ];
    foreach ($invalidMissing as $payload) {
        expect(fn () => $recordAction->handle($payload))
            ->toThrow(InvalidVat::class, 'A VAT direction, tax code, and transaction date are required.');
    }

    foreach (['net_amount', 'tax_amount', 'tax_rate'] as $negField) {
        expect(fn () => $recordAction->handle([
            'team_id' => 1,
            'direction' => 'output',
            'tax_code' => 'STD',
            'net_amount' => 10,
            'tax_amount' => 2,
            'tax_rate' => 20,
            'occurred_on' => '2026-01-01',
            $negField => -1,
        ]))->toThrow(InvalidVat::class, 'VAT amounts and rates cannot be negative.');
    }
});

it('records and updates digital vat evidence and rejects empty payloads', function (): void {
    $record = app(RecordVatTransaction::class)->handle([
        'team_id' => 2,
        'direction' => 'output',
        'tax_code' => 'STD20',
        'net_amount' => 200,
        'tax_amount' => 40,
        'occurred_on' => '2026-02-01',
    ]);

    $digitalAction = app(RecordVatDigitalEvidence::class);

    expect(fn () => $digitalAction->handle($record, []))
        ->toThrow(InvalidVat::class, 'Digital VAT evidence cannot be empty.');

    $first = $digitalAction->handle($record, ['invoice' => 'INV-100', 'total' => 240]);
    expect($first)->toBeInstanceOf(VatDigitalRecord::class)
        ->and($first->vatRecord->is($record))->toBeTrue()
        ->and($first->payload)->toBe(['invoice' => 'INV-100', 'total' => 240])
        ->and($first->recorded_at)->not->toBeNull();

    $second = $digitalAction->handle($record, ['invoice' => 'INV-100', 'total' => 250]);
    expect($second->id)->toBe($first->id)
        ->and($record->digitalRecord()->first()->payload)->toBe(['invoice' => 'INV-100', 'total' => 250]);
});

it('creates, adjusts, submits vat returns and computes box totals', function (): void {
    $createReturn = app(CreateVatReturn::class);
    $addAdjustment = app(AddVatAdjustment::class);
    $submitReturn = app(SubmitVatReturn::class);
    $recordTx = app(RecordVatTransaction::class);
    $boxesQuery = app(VatReturnBoxes::class);

    foreach ([
        ['period_start' => '2026-01-01', 'period_end' => '2026-03-31'],
        ['team_id' => 3, 'period_end' => '2026-03-31'],
        ['team_id' => 3, 'period_start' => '2026-01-01'],
    ] as $missing) {
        expect(fn () => $createReturn->handle($missing))
            ->toThrow(InvalidVat::class, 'A team and VAT return period are required.');
    }

    expect(fn () => $createReturn->handle([
        'team_id' => 3,
        'period_start' => '2026-04-01',
        'period_end' => '2026-03-31',
    ]))->toThrow(InvalidVat::class, 'A VAT return period must be chronological.');

    $emptyReturn = $createReturn->handle([
        'team_id' => 3,
        'period_start' => '2026-01-01',
        'period_end' => '2026-03-31',
    ]);

    expect($emptyReturn)->toBeInstanceOf(VatReturn::class)
        ->and($emptyReturn->scheme)->toBe('standard')
        ->and($emptyReturn->status)->toBe(VatReturnStatus::Draft)
        ->and($emptyReturn->boxes)->toBe([])
        ->and($boxesQuery->handle($emptyReturn))->toBe([]);

    $recordTx->handle([
        'team_id' => 3,
        'direction' => 'output',
        'tax_code' => 'STD20',
        'net_amount' => 100,
        'tax_amount' => 20,
        'box' => 1,
        'occurred_on' => '2026-02-10',
    ]);
    $recordTx->handle([
        'team_id' => 3,
        'direction' => 'output',
        'tax_code' => 'STD20',
        'net_amount' => 50,
        'tax_amount' => 10,
        'box' => 1,
        'occurred_on' => '2026-03-01',
    ]);
    $recordTx->handle([
        'team_id' => 3,
        'direction' => 'input',
        'tax_code' => 'STD20',
        'net_amount' => 40,
        'tax_amount' => 8,
        'box' => 4,
        'occurred_on' => '2026-02-15',
    ]);
    // Out of period / different scheme / different team should be excluded.
    $recordTx->handle([
        'team_id' => 3,
        'direction' => 'output',
        'tax_code' => 'STD20',
        'net_amount' => 999,
        'tax_amount' => 199,
        'box' => 1,
        'occurred_on' => '2026-04-01',
    ]);
    $recordTx->handle([
        'team_id' => 3,
        'direction' => 'output',
        'tax_code' => 'FLAT',
        'net_amount' => 500,
        'tax_amount' => 50,
        'box' => 1,
        'scheme' => 'flat_rate',
        'occurred_on' => '2026-02-20',
    ]);

    expect($boxesQuery->handle($emptyReturn))->toBe([
        1 => '30',
        4 => '8',
    ]);

    $emptyReturn->update(['boxes' => [4 => '12.50', 5 => '17.50']]);
    expect($boxesQuery->handle($emptyReturn->refresh()))->toBe([
        1 => '30',
        4 => '12.50',
        5 => '17.50',
    ]);

    foreach ([
        ['amount' => 5, 'reason' => 'Fix'],
        ['box' => 1, 'amount' => 5, 'reason' => ''],
        ['box' => 1, 'reason' => 'Fix'],
    ] as $invalidAdj) {
        expect(fn () => $addAdjustment->handle($emptyReturn, $invalidAdj))
            ->toThrow(InvalidVat::class, 'A VAT box, adjustment amount, and reason are required.');
    }

    $adjustment = $addAdjustment->handle($emptyReturn, [
        'box' => 1,
        'amount' => '15.250000',
        'reason' => 'Credit note timing adjustment',
        'created_by' => 9,
    ]);

    expect($adjustment)->toBeInstanceOf(VatAdjustment::class)
        ->and($adjustment->amount)->toBe('15.250000')
        ->and($adjustment->vatReturn->is($emptyReturn))->toBeTrue()
        ->and($emptyReturn->adjustments()->count())->toBe(1);

    $submitted = $submitReturn->handle($emptyReturn, 'HMRC-2026-Q1');
    expect($submitted->status)->toBe(VatReturnStatus::Submitted)
        ->and($submitted->submission_ref)->toBe('HMRC-2026-Q1')
        ->and($submitted->submitted_at)->not->toBeNull();

    expect(fn () => $submitReturn->handle($submitted, 'HMRC-RETRY'))
        ->toThrow(InvalidVat::class, 'Only draft VAT returns can be submitted.');
});
