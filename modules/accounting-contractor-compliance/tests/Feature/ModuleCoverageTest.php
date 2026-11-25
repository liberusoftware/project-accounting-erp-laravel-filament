<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Liberu\Accounting\ContractorCompliance\Actions\ExportContractorCompliance;
use Liberu\Accounting\ContractorCompliance\Actions\IssueContractorStatement;
use Liberu\Accounting\ContractorCompliance\Actions\RecordContractorEvidence;
use Liberu\Accounting\ContractorCompliance\Actions\RegisterContractor;
use Liberu\Accounting\ContractorCompliance\Actions\UpdateContractorCompliance;
use Liberu\Accounting\ContractorCompliance\ContractorComplianceServiceProvider;
use Liberu\Accounting\ContractorCompliance\Enums\ContractorComplianceStatus;
use Liberu\Accounting\ContractorCompliance\Exceptions\InvalidContractorCompliance;
use Liberu\Accounting\ContractorCompliance\Models\Contractor;
use Liberu\Accounting\ContractorCompliance\Queries\ContractorQuery;

it('registers the contractor compliance service provider and runs migrations', function (): void {
    expect(app()->getProvider(ContractorComplianceServiceProvider::class))
        ->toBeInstanceOf(ContractorComplianceServiceProvider::class);

    expect(Schema::hasTable('accounting_contractors'))->toBeTrue();
    expect(Schema::hasColumns('accounting_contractors', [
        'id',
        'team_id',
        'contractor_ref',
        'legal_name',
        'classification',
        'status',
        'withholding_scheme',
        'deductions',
        'evidence',
        'statement',
        'regional_export',
    ]))->toBeTrue();
});

it('exposes all contractor compliance status enum cases', function (): void {
    expect(array_column(ContractorComplianceStatus::cases(), 'value'))
        ->toBe(['active', 'suspended', 'archived']);
});

it('registers contractors and validates required attributes', function (): void {
    $register = new RegisterContractor();

    $contractor = $register->handle([
        'team_id' => 41,
        'contractor_ref' => 'CTR-001',
        'legal_name' => 'Jane Doe Consulting Ltd',
        'classification' => 'sole_trader',
        'withholding_scheme' => 'standard',
    ]);

    expect($contractor)->toBeInstanceOf(Contractor::class)
        ->and($contractor->status)->toBe(ContractorComplianceStatus::Active)
        ->and($contractor->legal_name)->toBe('Jane Doe Consulting Ltd');

    foreach (['team_id', 'contractor_ref', 'legal_name', 'classification'] as $missingField) {
        $payload = [
            'team_id' => 41,
            'contractor_ref' => 'CTR-ERR',
            'legal_name' => 'Err Ltd',
            'classification' => 'company',
        ];
        unset($payload[$missingField]);

        expect(fn () => $register->handle($payload))
            ->toThrow(InvalidContractorCompliance::class, "{$missingField} is required.");
    }
});

it('updates compliance, records evidence, issues statements, exports compliance, and guards archived contractors', function (): void {
    $register = new RegisterContractor();
    $update = new UpdateContractorCompliance();
    $recordEvidence = new RecordContractorEvidence();
    $issueStatement = new IssueContractorStatement();
    $export = new ExportContractorCompliance();

    $contractor = $register->handle([
        'team_id' => 42,
        'contractor_ref' => 'CTR-002',
        'legal_name' => 'Northwind Contractors',
        'classification' => 'llc',
    ]);

    expect(fn () => $recordEvidence->handle($contractor, ['reference' => 'DOC-1']))
        ->toThrow(InvalidContractorCompliance::class, 'Evidence type and reference are required.');
    expect(fn () => $recordEvidence->handle($contractor, ['type' => 'w9']))
        ->toThrow(InvalidContractorCompliance::class, 'Evidence type and reference are required.');

    $contractor = $recordEvidence->handle($contractor, ['type' => 'w9', 'reference' => 'DOC-W9-1']);
    $contractor = $recordEvidence->handle($contractor, ['type' => 'insurance', 'reference' => 'POL-99']);
    expect($contractor->evidence)->toHaveCount(2);

    expect(fn () => $issueStatement->handle($contractor, []))
        ->toThrow(InvalidContractorCompliance::class, 'Only active contractors with a statement period can receive statements.');

    $contractor = $issueStatement->handle($contractor, ['period' => '2026-08', 'total_paid' => 4200]);
    expect($contractor->statement)->toBe(['period' => '2026-08', 'total_paid' => 4200]);

    expect(fn () => $export->handle($contractor, ''))
        ->toThrow(InvalidContractorCompliance::class, 'A regional export destination is required.');

    $contractor = $export->handle($contractor, 'uk-hmrc');
    expect($contractor->regional_export['region'])->toBe('uk-hmrc')
        ->and($contractor->regional_export['exported_at'])->not->toBeEmpty();

    $contractor = $update->handle($contractor, [
        'withholding_scheme' => 'backup_24',
        'deductions' => [['code' => 'BACKUP', 'rate' => 24]],
        'status' => ContractorComplianceStatus::Suspended,
    ]);
    expect($contractor->status)->toBe(ContractorComplianceStatus::Suspended)
        ->and($contractor->deductions)->toBe([['code' => 'BACKUP', 'rate' => 24]]);

    expect(fn () => $issueStatement->handle($contractor, ['period' => '2026-09']))
        ->toThrow(InvalidContractorCompliance::class);

    $archived = $update->handle($contractor, ['status' => ContractorComplianceStatus::Archived]);
    expect($archived->status)->toBe(ContractorComplianceStatus::Archived);

    expect(fn () => $update->handle($archived, ['legal_name' => 'Should Fail']))
        ->toThrow(InvalidContractorCompliance::class, 'Archived contractors cannot be changed.');
});

it('queries contractors by team ordered by latest id', function (): void {
    $register = new RegisterContractor();
    $query = new ContractorQuery();

    expect($query->forTeam(777))->toBeEmpty();

    $first = $register->handle([
        'team_id' => 45,
        'contractor_ref' => 'CTR-A',
        'legal_name' => 'Alpha',
        'classification' => 'individual',
    ]);

    $second = $register->handle([
        'team_id' => 45,
        'contractor_ref' => 'CTR-B',
        'legal_name' => 'Beta',
        'classification' => 'individual',
    ]);

    $register->handle([
        'team_id' => 46,
        'contractor_ref' => 'CTR-C',
        'legal_name' => 'Gamma',
        'classification' => 'individual',
    ]);

    $results = $query->forTeam(45);

    expect($results)->toHaveCount(2)
        ->and($results->pluck('id')->all())->toBe([$second->id, $first->id]);
});
