<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Liberu\Accounting\WithholdingTax\Actions\CalculateWithholdingTax;
use Liberu\Accounting\WithholdingTax\Actions\CreateWithholdingTaxRule;
use Liberu\Accounting\WithholdingTax\Actions\RemitWithholdingTax;
use Liberu\Accounting\WithholdingTax\Enums\WithholdingStatus;
use Liberu\Accounting\WithholdingTax\Exceptions\InvalidWithholdingTax;
use Liberu\Accounting\WithholdingTax\Models\WithholdingTaxCertificate;
use Liberu\Accounting\WithholdingTax\Models\WithholdingTaxDeduction;
use Liberu\Accounting\WithholdingTax\Models\WithholdingTaxFilingAdapter;
use Liberu\Accounting\WithholdingTax\Models\WithholdingTaxLiability;
use Liberu\Accounting\WithholdingTax\Models\WithholdingTaxRemittance;
use Liberu\Accounting\WithholdingTax\Models\WithholdingTaxRule;
use Liberu\Accounting\WithholdingTax\Models\WithholdingTaxStatement;
use Liberu\Accounting\WithholdingTax\WithholdingTaxServiceProvider;

it('registers withholding tax service provider and creates all tables', function (): void {
    expect(app()->getProvider(WithholdingTaxServiceProvider::class))
        ->toBeInstanceOf(WithholdingTaxServiceProvider::class);

    foreach ([
        'accounting_withholding_tax_rules',
        'accounting_withholding_tax_certificates',
        'accounting_withholding_tax_deductions',
        'accounting_withholding_tax_liabilities',
        'accounting_withholding_tax_remittances',
        'accounting_withholding_tax_statements',
        'accounting_withholding_tax_filing_adapters',
    ] as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }
});

it('exposes withholding status enum cases', function (): void {
    expect(array_map(static fn (WithholdingStatus $s): string => $s->value, WithholdingStatus::cases()))
        ->toBe(['draft', 'active', 'calculated', 'open', 'submitted', 'remitted', 'filed', 'cancelled']);
});

it('creates withholding tax rules and validates required fields and rate bounds', function (): void {
    $createRule = app(CreateWithholdingTaxRule::class);

    $rule = $createRule->handle([
        'team_id' => 1,
        'code' => 'WHT-US-30',
        'name' => 'US Non-Resident Services',
        'jurisdiction' => 'US',
        'rate' => 30,
        'threshold' => 600,
        'effective_from' => '2026-01-01',
        'effective_until' => '2026-12-31',
        'metadata' => ['form' => '1042-S'],
    ]);

    expect($rule)->toBeInstanceOf(WithholdingTaxRule::class)
        ->and($rule->status)->toBe(WithholdingStatus::Draft)
        ->and($rule->rate)->toBe('30.0000')
        ->and($rule->threshold)->toBe('600.00')
        ->and($rule->effective_from->toDateString())->toBe('2026-01-01')
        ->and($rule->effective_until->toDateString())->toBe('2026-12-31')
        ->and($rule->metadata)->toBe(['form' => '1042-S']);

    $zeroRule = $createRule->handle([
        'team_id' => 1,
        'code' => 'WHT-EXEMPT',
        'name' => 'Treaty Zero Rate',
        'jurisdiction' => 'GB',
        'rate' => 0,
        'effective_from' => '2026-01-01',
        'status' => WithholdingStatus::Active,
    ]);
    expect($zeroRule->status)->toBe(WithholdingStatus::Active)
        ->and($zeroRule->rate)->toBe('0.0000');

    $hundredRule = $createRule->handle([
        'team_id' => 1,
        'code' => 'WHT-MAX',
        'name' => 'Full Withholding',
        'jurisdiction' => 'GB',
        'rate' => 100,
        'effective_from' => '2026-01-01',
    ]);
    expect($hundredRule->rate)->toBe('100.0000');

    $invalidPayloads = [
        ['team_id' => 1, 'name' => 'N', 'jurisdiction' => 'US', 'rate' => 10, 'effective_from' => '2026-01-01'],
        ['team_id' => 1, 'code' => 'C', 'jurisdiction' => 'US', 'rate' => 10, 'effective_from' => '2026-01-01'],
        ['team_id' => 1, 'code' => 'C', 'name' => 'N', 'rate' => 10, 'effective_from' => '2026-01-01'],
        ['team_id' => 1, 'code' => 'C', 'name' => 'N', 'jurisdiction' => 'US', 'rate' => 10],
        ['team_id' => 1, 'code' => 'C', 'name' => 'N', 'jurisdiction' => 'US', 'effective_from' => '2026-01-01'],
        ['team_id' => 1, 'code' => 'C', 'name' => 'N', 'jurisdiction' => 'US', 'rate' => -0.1, 'effective_from' => '2026-01-01'],
        ['team_id' => 1, 'code' => 'C', 'name' => 'N', 'jurisdiction' => 'US', 'rate' => 100.1, 'effective_from' => '2026-01-01'],
    ];

    foreach ($invalidPayloads as $payload) {
        expect(fn () => $createRule->handle($payload))
            ->toThrow(InvalidWithholdingTax::class, 'Code, name, jurisdiction, effective date, and a rate between 0 and 100 are required.');
    }
});

it('calculates withholding deductions respecting threshold and remits liabilities', function (): void {
    $rule = app(CreateWithholdingTaxRule::class)->handle([
        'team_id' => 2,
        'code' => 'CIS-20',
        'name' => 'CIS Standard',
        'jurisdiction' => 'GB',
        'rate' => 20,
        'threshold' => 500,
        'effective_from' => '2026-01-01',
    ]);

    $calculate = app(CalculateWithholdingTax::class);

    $atThreshold = $calculate->handle($rule, [
        'party_type' => 'Vendor',
        'party_id' => 'V-1',
        'source_ref' => 'BILL-500',
        'currency' => 'GBP',
        'gross_amount' => 500.00,
    ]);
    expect($atThreshold->withheld_amount)->toBe('0.00')
        ->and($atThreshold->status)->toBe(WithholdingStatus::Calculated);

    $aboveThreshold = $calculate->handle($rule, [
        'party_type' => 'Vendor',
        'party_id' => 'V-1',
        'source_ref' => 'BILL-1000',
        'currency' => 'GBP',
        'gross_amount' => 1000.335,
        'metadata' => ['period' => '2026-03'],
    ]);

    expect($aboveThreshold)->toBeInstanceOf(WithholdingTaxDeduction::class)
        ->and($aboveThreshold->team_id)->toBe(2)
        ->and($aboveThreshold->gross_amount)->toBe('1000.34')
        ->and($aboveThreshold->withheld_amount)->toBe('200.07')
        ->and($aboveThreshold->metadata)->toBe(['period' => '2026-03'])
        ->and($rule->deductions()->count())->toBe(2);

    $liability = WithholdingTaxLiability::create([
        'team_id' => 2,
        'deduction_id' => $aboveThreshold->id,
        'amount' => 200.07,
        'due_on' => '2026-04-19',
        'status' => WithholdingStatus::Open,
        'metadata' => ['authority' => 'HMRC'],
    ]);

    expect($aboveThreshold->liability->is($liability))->toBeTrue()
        ->and($liability->status)->toBe(WithholdingStatus::Open)
        ->and($liability->due_on->toDateString())->toBe('2026-04-19');

    $remittance = app(RemitWithholdingTax::class)->handle($liability, [
        'amount' => 200.07,
        'remitted_on' => '2026-04-15',
        'reference' => 'HMRC-PAY-01',
        'metadata' => ['method' => 'faster_payments'],
    ]);

    expect($remittance)->toBeInstanceOf(WithholdingTaxRemittance::class)
        ->and($remittance->amount)->toBe('200.07')
        ->and($remittance->remitted_on->toDateString())->toBe('2026-04-15')
        ->and($remittance->status)->toBe(WithholdingStatus::Remitted->value)
        ->and($remittance->metadata)->toBe(['method' => 'faster_payments'])
        ->and($liability->refresh()->status)->toBe(WithholdingStatus::Remitted);
});

it('persists certificates, statements, and filing adapters with expected casts', function (): void {
    $rule = app(CreateWithholdingTaxRule::class)->handle([
        'team_id' => 3,
        'code' => 'W8-BEN',
        'name' => 'Treaty Certificate Rule',
        'jurisdiction' => 'US',
        'rate' => 15,
        'effective_from' => '2026-01-01',
    ]);

    $cert = WithholdingTaxCertificate::create([
        'team_id' => 3,
        'rule_id' => $rule->id,
        'party_type' => 'Vendor',
        'party_id' => 'V-9',
        'certificate_ref' => 'CERT-99',
        'valid_from' => '2026-01-01',
        'valid_until' => '2028-12-31',
        'status' => 'active',
        'metadata' => ['signed' => true],
    ]);

    $statement = WithholdingTaxStatement::create([
        'team_id' => 3,
        'jurisdiction' => 'US',
        'period_start' => '2026-01-01',
        'period_end' => '2026-12-31',
        'total_amount' => 1500.5,
        'status' => 'draft',
        'payload' => ['lines' => 3],
    ]);

    $adapter = WithholdingTaxFilingAdapter::create([
        'team_id' => 3,
        'jurisdiction' => 'US',
        'provider' => 'irs-fire',
        'status' => 'configured',
        'configuration' => ['tcc' => 'ABC12'],
    ]);

    expect($cert->valid_from->toDateString())->toBe('2026-01-01')
        ->and($cert->valid_until->toDateString())->toBe('2028-12-31')
        ->and($cert->metadata)->toBe(['signed' => true])
        ->and($statement->period_start->toDateString())->toBe('2026-01-01')
        ->and($statement->period_end->toDateString())->toBe('2026-12-31')
        ->and($statement->total_amount)->toBe('1500.50')
        ->and($statement->payload)->toBe(['lines' => 3])
        ->and($adapter->configuration)->toBe(['tcc' => 'ABC12']);
});
