<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Liberu\Accounting\ContractorReporting\Actions\CorrectContractorReport;
use Liberu\Accounting\ContractorReporting\Actions\CreateContractorReport;
use Liberu\Accounting\ContractorReporting\Actions\FileContractorReport;
use Liberu\Accounting\ContractorReporting\Actions\ValidateContractorReport;
use Liberu\Accounting\ContractorReporting\ContractorReportingServiceProvider;
use Liberu\Accounting\ContractorReporting\Enums\ContractorReportStatus;
use Liberu\Accounting\ContractorReporting\Exceptions\InvalidContractorReport;
use Liberu\Accounting\ContractorReporting\Models\ContractorReport;
use Liberu\Accounting\ContractorReporting\Queries\ContractorReportQuery;

it('registers the contractor reporting service provider and runs migrations', function (): void {
    expect(app()->getProvider(ContractorReportingServiceProvider::class))
        ->toBeInstanceOf(ContractorReportingServiceProvider::class);

    expect(Schema::hasTable('accounting_contractor_reports'))->toBeTrue();
    expect(Schema::hasColumns('accounting_contractor_reports', [
        'id',
        'team_id',
        'payee_ref',
        'tax_year',
        'classification',
        'threshold',
        'reportable_amount',
        'form_type',
        'status',
        'filing_adapter',
        'payee_validation',
        'correction',
        'evidence',
    ]))->toBeTrue();
});

it('exposes all contractor report status enum cases', function (): void {
    expect(array_column(ContractorReportStatus::cases(), 'value'))
        ->toBe(['draft', 'validated', 'filed', 'corrected']);
});

it('creates contractor reports and validates required attributes, tax year, and amounts', function (): void {
    $create = new CreateContractorReport();

    $defaultAmount = $create->handle([
        'team_id' => 51,
        'payee_ref' => 'PAYEE-1',
        'tax_year' => '2026',
        'classification' => 'nonemployee',
        'threshold' => 600,
        'form_type' => '1099-NEC',
    ]);

    expect($defaultAmount)->toBeInstanceOf(ContractorReport::class)
        ->and($defaultAmount->status)->toBe(ContractorReportStatus::Draft)
        ->and($defaultAmount->threshold)->toBe('600.00000000')
        ->and($defaultAmount->reportable_amount)->toBe('0.00000000');

    $withAmount = $create->handle([
        'team_id' => 51,
        'payee_ref' => 'PAYEE-2',
        'tax_year' => '2026',
        'classification' => 'misc',
        'threshold' => 600,
        'reportable_amount' => 2400.5,
        'form_type' => '1099-MISC',
        'evidence' => ['invoices' => ['INV-1']],
    ]);

    expect($withAmount->reportable_amount)->toBe('2400.50000000')
        ->and($withAmount->evidence)->toBe(['invoices' => ['INV-1']]);

    foreach (['team_id', 'payee_ref', 'tax_year', 'classification', 'threshold', 'form_type'] as $missingField) {
        $payload = [
            'team_id' => 51,
            'payee_ref' => 'PAYEE-ERR',
            'tax_year' => '2026',
            'classification' => 'nonemployee',
            'threshold' => 600,
            'form_type' => '1099-NEC',
        ];
        unset($payload[$missingField]);

        expect(fn () => $create->handle($payload))
            ->toThrow(InvalidContractorReport::class, "{$missingField} is required.");
    }

    expect(fn () => $create->handle([
        'team_id' => 51,
        'payee_ref' => 'PAYEE-BAD-YR',
        'tax_year' => '26',
        'classification' => 'nonemployee',
        'threshold' => 600,
        'form_type' => '1099-NEC',
    ]))->toThrow(InvalidContractorReport::class, 'Tax year and amounts are invalid.');

    expect(fn () => $create->handle([
        'team_id' => 51,
        'payee_ref' => 'PAYEE-NEG-THRESH',
        'tax_year' => '2026',
        'classification' => 'nonemployee',
        'threshold' => -1,
        'form_type' => '1099-NEC',
    ]))->toThrow(InvalidContractorReport::class, 'Tax year and amounts are invalid.');

    expect(fn () => $create->handle([
        'team_id' => 51,
        'payee_ref' => 'PAYEE-NEG-AMT',
        'tax_year' => '2026',
        'classification' => 'nonemployee',
        'threshold' => 600,
        'reportable_amount' => -10,
        'form_type' => '1099-NEC',
    ]))->toThrow(InvalidContractorReport::class, 'Tax year and amounts are invalid.');
});

it('validates, files, and corrects contractor reports with state guardrails', function (): void {
    $create = new CreateContractorReport();
    $validate = new ValidateContractorReport();
    $file = new FileContractorReport();
    $correct = new CorrectContractorReport();

    $report = $create->handle([
        'team_id' => 52,
        'payee_ref' => 'PAYEE-10',
        'tax_year' => '2026',
        'classification' => 'nonemployee',
        'threshold' => 600,
        'reportable_amount' => 3200,
        'form_type' => '1099-NEC',
    ]);

    expect(fn () => $file->handle($report, 'irs-fire'))
        ->toThrow(InvalidContractorReport::class, 'Only validated reports can be filed.');
    expect(fn () => $correct->handle($report, ['reason' => 'Wrong TIN']))
        ->toThrow(InvalidContractorReport::class, 'Only filed reports with a correction reason can be corrected.');

    expect(fn () => $validate->handle($report, ['legal_name' => 'Acme LLC']))
        ->toThrow(InvalidContractorReport::class, 'Only draft reports with validated payee identity can be validated.');
    expect(fn () => $validate->handle($report, ['tax_id' => '12-3456789']))
        ->toThrow(InvalidContractorReport::class, 'Only draft reports with validated payee identity can be validated.');

    $report = $validate->handle($report, ['tax_id' => '12-3456789', 'legal_name' => 'Acme LLC']);
    expect($report->status)->toBe(ContractorReportStatus::Validated)
        ->and($report->payee_validation)->toBe(['tax_id' => '12-3456789', 'legal_name' => 'Acme LLC']);

    expect(fn () => $validate->handle($report, ['tax_id' => '12-3456789', 'legal_name' => 'Acme LLC']))
        ->toThrow(InvalidContractorReport::class);

    expect(fn () => $file->handle($report, ''))
        ->toThrow(InvalidContractorReport::class);

    $report = $file->handle($report, 'irs-fire');
    expect($report->status)->toBe(ContractorReportStatus::Filed)
        ->and($report->filing_adapter)->toBe('irs-fire');

    expect(fn () => $correct->handle($report, []))
        ->toThrow(InvalidContractorReport::class);

    $report = $correct->handle($report, ['reason' => 'Amended box 1 amount', 'new_amount' => 3500]);
    expect($report->status)->toBe(ContractorReportStatus::Corrected)
        ->and($report->correction)->toBe(['reason' => 'Amended box 1 amount', 'new_amount' => 3500]);

    expect(fn () => $correct->handle($report, ['reason' => 'Second correction']))
        ->toThrow(InvalidContractorReport::class);
});

it('queries contractor reports by team', function (): void {
    $create = new CreateContractorReport();
    $query = new ContractorReportQuery();

    expect($query->forTeam(777))->toBeEmpty();

    $this->travel(-2)->minutes();
    $first = $create->handle([
        'team_id' => 55,
        'payee_ref' => 'PAYEE-A',
        'tax_year' => '2026',
        'classification' => 'nonemployee',
        'threshold' => 600,
        'form_type' => '1099-NEC',
    ]);

    $this->travel(2)->minutes();
    $second = $create->handle([
        'team_id' => 55,
        'payee_ref' => 'PAYEE-B',
        'tax_year' => '2026',
        'classification' => 'nonemployee',
        'threshold' => 600,
        'form_type' => '1099-NEC',
    ]);

    $create->handle([
        'team_id' => 56,
        'payee_ref' => 'PAYEE-C',
        'tax_year' => '2026',
        'classification' => 'nonemployee',
        'threshold' => 600,
        'form_type' => '1099-NEC',
    ]);

    $results = $query->forTeam(55);

    expect($results)->toHaveCount(2)
        ->and($results->pluck('id')->all())->toBe([$second->id, $first->id]);
});
