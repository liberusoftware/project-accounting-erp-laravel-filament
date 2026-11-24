<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Liberu\Accounting\ConstructionTax\Actions\CorrectConstructionTaxReturn;
use Liberu\Accounting\ConstructionTax\Actions\CreateConstructionTaxRecord;
use Liberu\Accounting\ConstructionTax\Actions\IssueConstructionTaxStatement;
use Liberu\Accounting\ConstructionTax\Actions\SubmitConstructionTaxReturn;
use Liberu\Accounting\ConstructionTax\Actions\VerifySubcontractor;
use Liberu\Accounting\ConstructionTax\ConstructionTaxServiceProvider;
use Liberu\Accounting\ConstructionTax\Enums\ConstructionTaxStatus;
use Liberu\Accounting\ConstructionTax\Exceptions\InvalidConstructionTax;
use Liberu\Accounting\ConstructionTax\Models\ConstructionTaxRecord;
use Liberu\Accounting\ConstructionTax\Queries\ConstructionTaxQuery;

it('registers the construction tax service provider and runs migrations', function (): void {
    expect(app()->getProvider(ConstructionTaxServiceProvider::class))
        ->toBeInstanceOf(ConstructionTaxServiceProvider::class);

    expect(Schema::hasTable('accounting_construction_tax_records'))->toBeTrue();
    expect(Schema::hasColumns('accounting_construction_tax_records', [
        'id',
        'team_id',
        'subcontractor_ref',
        'verification_status',
        'deduction_rate',
        'tax_period',
        'gross_amount',
        'deduction_amount',
        'return_status',
        'filing_adapter',
        'verification',
        'statement',
        'correction',
    ]))->toBeTrue();
});

it('exposes all construction tax status enum cases', function (): void {
    expect(array_column(ConstructionTaxStatus::cases(), 'value'))
        ->toBe(['pending', 'verified', 'submitted', 'corrected']);
});

it('creates construction tax records and validates rates and amounts', function (): void {
    $create = new CreateConstructionTaxRecord();

    $computed = $create->handle([
        'team_id' => 31,
        'subcontractor_ref' => 'SUB-001',
        'tax_period' => '2026-08',
        'deduction_rate' => 20,
        'gross_amount' => 1000,
    ])->refresh();

    expect($computed)->toBeInstanceOf(ConstructionTaxRecord::class)
        ->and($computed->verification_status)->toBe(ConstructionTaxStatus::Pending)
        ->and($computed->return_status)->toBe('draft')
        ->and($computed->deduction_rate)->toBe('20.0000')
        ->and($computed->gross_amount)->toBe('1000.00000000')
        ->and($computed->deduction_amount)->toBe('200.00000000');

    $explicit = $create->handle([
        'team_id' => 31,
        'subcontractor_ref' => 'SUB-002',
        'tax_period' => '2026-08',
        'deduction_rate' => 30,
        'gross_amount' => 1000,
        'deduction_amount' => 250,
    ]);

    expect($explicit->deduction_amount)->toBe('250.00000000');

    foreach (['team_id', 'subcontractor_ref', 'tax_period'] as $missingField) {
        $payload = [
            'team_id' => 31,
            'subcontractor_ref' => 'SUB-ERR',
            'tax_period' => '2026-08',
        ];
        unset($payload[$missingField]);

        expect(fn () => $create->handle($payload))
            ->toThrow(InvalidConstructionTax::class, "{$missingField} is required.");
    }

    expect(fn () => $create->handle([
        'team_id' => 31,
        'subcontractor_ref' => 'SUB-BAD-RATE-LOW',
        'tax_period' => '2026-08',
        'deduction_rate' => -1,
    ]))->toThrow(InvalidConstructionTax::class, 'Deduction rate and gross amount are invalid.');

    expect(fn () => $create->handle([
        'team_id' => 31,
        'subcontractor_ref' => 'SUB-BAD-RATE-HIGH',
        'tax_period' => '2026-08',
        'deduction_rate' => 101,
    ]))->toThrow(InvalidConstructionTax::class, 'Deduction rate and gross amount are invalid.');

    expect(fn () => $create->handle([
        'team_id' => 31,
        'subcontractor_ref' => 'SUB-BAD-GROSS',
        'tax_period' => '2026-08',
        'gross_amount' => -10,
    ]))->toThrow(InvalidConstructionTax::class, 'Deduction rate and gross amount are invalid.');
});

it('verifies subcontractors, submits returns, issues statements, and corrects returns', function (): void {
    $create = new CreateConstructionTaxRecord();
    $verify = new VerifySubcontractor();
    $submit = new SubmitConstructionTaxReturn();
    $issueStatement = new IssueConstructionTaxStatement();
    $correct = new CorrectConstructionTaxReturn();

    $record = $create->handle([
        'team_id' => 32,
        'subcontractor_ref' => 'SUB-100',
        'tax_period' => '2026-09',
        'deduction_rate' => 20,
        'gross_amount' => 2500,
    ]);

    expect(fn () => $submit->handle($record, 'hmrc-cis'))
        ->toThrow(InvalidConstructionTax::class, 'Only verified records can be submitted with a filing adapter.');

    expect(fn () => $correct->handle($record, ['reason' => 'Amendment']))
        ->toThrow(InvalidConstructionTax::class, 'Only submitted returns with a correction reason can be corrected.');

    expect(fn () => $verify->handle($record, []))
        ->toThrow(InvalidConstructionTax::class, 'Only pending records with a verification reference can be verified.');

    $record = $verify->handle($record, ['reference' => 'V1234567', 'matched' => true]);
    expect($record->verification_status)->toBe(ConstructionTaxStatus::Verified)
        ->and($record->verification)->toBe(['reference' => 'V1234567', 'matched' => true]);

    expect(fn () => $verify->handle($record, ['reference' => 'V999']))
        ->toThrow(InvalidConstructionTax::class);

    expect(fn () => $submit->handle($record, ''))
        ->toThrow(InvalidConstructionTax::class);

    $record = $submit->handle($record, 'hmrc-cis');
    expect($record->return_status)->toBe(ConstructionTaxStatus::Submitted->value)
        ->and($record->filing_adapter)->toBe('hmrc-cis');

    expect(fn () => $issueStatement->handle($record, []))
        ->toThrow(InvalidConstructionTax::class, 'A statement reference is required.');

    $record = $issueStatement->handle($record, ['reference' => 'STMT-2026-09', 'issued_on' => '2026-10-01']);
    expect($record->statement)->toBe(['reference' => 'STMT-2026-09', 'issued_on' => '2026-10-01']);

    expect(fn () => $correct->handle($record, []))
        ->toThrow(InvalidConstructionTax::class);

    $record = $correct->handle($record, ['reason' => 'Material cost adjustment', 'delta' => -50]);
    expect($record->verification_status)->toBe(ConstructionTaxStatus::Corrected)
        ->and($record->correction)->toBe(['reason' => 'Material cost adjustment', 'delta' => -50]);
});

it('queries construction tax records by team ordered by latest id', function (): void {
    $create = new CreateConstructionTaxRecord();
    $query = new ConstructionTaxQuery();

    expect($query->forTeam(777))->toBeEmpty();

    $first = $create->handle([
        'team_id' => 35,
        'subcontractor_ref' => 'SUB-1',
        'tax_period' => '2026-08',
    ]);

    $second = $create->handle([
        'team_id' => 35,
        'subcontractor_ref' => 'SUB-2',
        'tax_period' => '2026-08',
    ]);

    $create->handle([
        'team_id' => 36,
        'subcontractor_ref' => 'SUB-3',
        'tax_period' => '2026-08',
    ]);

    $results = $query->forTeam(35);

    expect($results)->toHaveCount(2)
        ->and($results->pluck('id')->all())->toBe([$second->id, $first->id]);
});
