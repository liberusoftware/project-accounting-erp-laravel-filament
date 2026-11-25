<?php

declare(strict_types=1);

use Liberu\Accounting\TaxCore\Actions\ActivateTaxRule;
use Liberu\Accounting\TaxCore\Actions\ArchiveTaxRule;
use Liberu\Accounting\TaxCore\Actions\CalculateTax;
use Liberu\Accounting\TaxCore\Actions\CaptureTaxEvidence;
use Liberu\Accounting\TaxCore\Actions\CreateTaxRule;
use Liberu\Accounting\TaxCore\Actions\UpdateTaxRule;
use Liberu\Accounting\TaxCore\Enums\TaxRuleStatus;
use Liberu\Accounting\TaxCore\Enums\TaxTreatment;
use Liberu\Accounting\TaxCore\Events\TaxEvidenceCaptured;
use Liberu\Accounting\TaxCore\Events\TaxRuleCreated;
use Liberu\Accounting\TaxCore\Events\TaxRuleUpdated;
use Liberu\Accounting\TaxCore\Exceptions\InvalidTaxRule;
use Liberu\Accounting\TaxCore\Models\TaxEvidence;
use Liberu\Accounting\TaxCore\Models\TaxRule;
use Liberu\Accounting\TaxCore\Queries\ActiveTaxRuleQuery;
use Liberu\Accounting\TaxCore\Queries\TaxRuleQuery;
use Liberu\Accounting\TaxCore\TaxCoreServiceProvider;

it('boots tax core service provider and covers enums and exception', function (): void {
    $provider = $this->app->register(TaxCoreServiceProvider::class, force: true);
    $provider->boot();

    expect($provider)->toBeInstanceOf(TaxCoreServiceProvider::class)
        ->and(array_column(TaxRuleStatus::cases(), 'value'))->toBe(['draft', 'active', 'archived'])
        ->and(array_column(TaxTreatment::cases(), 'value'))->toBe(['exclusive', 'inclusive', 'exempt', 'zero_rated'])
        ->and((new InvalidTaxRule('Tax error'))->getMessage())->toBe('Tax error');
});

it('manages tax rule lifecycle, calculations, evidence capture, and queries', function (): void {
    $create = app(CreateTaxRule::class);
    $update = app(UpdateTaxRule::class);
    $activate = app(ActivateTaxRule::class);
    $archive = app(ArchiveTaxRule::class);
    $calculate = app(CalculateTax::class);
    $capture = app(CaptureTaxEvidence::class);
    $activeQuery = app(ActiveTaxRuleQuery::class);
    $listQuery = app(TaxRuleQuery::class);

    $rule = $create->handle([
        'code' => 'VAT-STD',
        'name' => 'Standard VAT',
        'tax_type' => 'vat',
        'jurisdiction_code' => 'GB',
        'rate' => 20,
        'effective_from' => '2026-01-01',
        'effective_until' => '2026-12-31',
        'control_account_code' => '2200',
        'metadata' => ['authority' => 'HMRC'],
    ]);

    expect($rule)->toBeInstanceOf(TaxRule::class)
        ->and($rule->status)->toBe(TaxRuleStatus::Draft)
        ->and($rule->treatment)->toBe(TaxTreatment::Exclusive)
        ->and($rule->rounding_method)->toBe('half_up')
        ->and($rule->rounding_scale)->toBe(2)
        ->and((new TaxRuleCreated($rule))->taxRule->is($rule))->toBeTrue();

    // Update rule
    $updated = $update->handle($rule, ['name' => 'UK Standard VAT']);
    expect($updated->name)->toBe('UK Standard VAT')
        ->and((new TaxRuleUpdated($updated))->taxRule->is($updated))->toBeTrue();

    // Activate rule and test ActiveTaxRuleQuery + scopeActive
    expect($activeQuery->handle('VAT-STD', 'GB', '2026-06-15'))->toBeNull();

    $active = $activate->handle($updated);
    expect($active->status)->toBe(TaxRuleStatus::Active);

    $found = $activeQuery->handle('VAT-STD', 'GB', '2026-06-15');
    expect($found)->not->toBeNull()
        ->and($found->is($active))->toBeTrue()
        ->and($activeQuery->handle('VAT-STD', null, '2026-06-15')?->is($active))->toBeTrue()
        ->and($activeQuery->handle('VAT-STD', 'GB', '2027-01-01'))->toBeNull();

    // Test scopeActive with default date (null) and open-ended effective_until
    $openEnded = $create->handle([
        'code' => 'GST-OPEN',
        'name' => 'Open-ended GST',
        'tax_type' => 'gst',
        'rate' => 10,
        'treatment' => TaxTreatment::Inclusive,
        'effective_from' => now()->subDays(10)->toDateString(),
        'status' => TaxRuleStatus::Active,
    ]);
    expect(TaxRule::query()->active()->whereKey($openEnded->id)->exists())->toBeTrue();

    // Tax calculations across all treatments
    $exclusiveCalc = $calculate->handle($active, 100.00);
    expect($exclusiveCalc)->toBe([
        'net' => 100.0,
        'tax' => 20.0,
        'gross' => 120.0,
        'rate' => 20.0,
        'rounding_scale' => 2,
    ]);

    $inclusiveCalc = $calculate->handle($openEnded, 110.00);
    expect($inclusiveCalc)->toBe([
        'net' => 100.0,
        'tax' => 10.0,
        'gross' => 110.0,
        'rate' => 10.0,
        'rounding_scale' => 2,
    ]);

    $exemptRule = $create->handle([
        'code' => 'VAT-EXEMPT',
        'name' => 'Exempt',
        'tax_type' => 'vat',
        'rate' => 0,
        'treatment' => TaxTreatment::Exempt,
        'effective_from' => '2026-01-01',
    ]);
    expect($calculate->handle($exemptRule, 50.00))->toBe([
        'net' => 50.0,
        'tax' => 0.0,
        'gross' => 50.0,
        'rate' => 0.0,
        'rounding_scale' => 2,
    ]);

    $zeroRule = $create->handle([
        'code' => 'VAT-ZERO',
        'name' => 'Zero Rated',
        'tax_type' => 'vat',
        'rate' => 0,
        'treatment' => TaxTreatment::ZeroRated,
        'effective_from' => '2026-01-01',
    ]);
    expect($calculate->handle($zeroRule, 75.50)['tax'])->toBe(0.0);

    // Capture evidence (with idempotency and hidden fields)
    $snapshot = ['country' => 'GB', 'vat_number' => 'GB123456789'];
    $evidence = $capture->handle($active, 'invoice', 'INV-1', $snapshot, 7);
    $duplicateEvidence = $capture->handle($active, 'invoice', 'INV-1', $snapshot, 7);

    expect($evidence)->toBeInstanceOf(TaxEvidence::class)
        ->and($duplicateEvidence->is($evidence))->toBeTrue()
        ->and($evidence->taxRule->is($active))->toBeTrue()
        ->and($active->evidence()->count())->toBe(1)
        ->and($evidence->toArray())->not->toHaveKeys(['snapshot_hash', 'snapshot'])
        ->and((new TaxEvidenceCaptured($evidence))->evidence->is($evidence))->toBeTrue();

    // Archive rule
    $archived = $archive->handle($active);
    expect($archived->status)->toBe(TaxRuleStatus::Archived);

    // Paginated query with and without status filter + clamping
    $allPage = $listQuery->paginate(null, 200);
    expect($allPage->total())->toBe(4)
        ->and($allPage->perPage())->toBe(100);

    $archivedPage = $listQuery->paginate('archived', 0);
    expect($archivedPage->total())->toBe(1)
        ->and($archivedPage->perPage())->toBe(1);
});

it('enforces validation rules for tax rules and calculations', function (): void {
    $create = app(CreateTaxRule::class);
    $calculate = app(CalculateTax::class);

    $valid = [
        'code' => 'VAT-VAL',
        'name' => 'Validation VAT',
        'tax_type' => 'vat',
        'jurisdiction_code' => 'DE',
        'rate' => 19,
        'effective_from' => '2026-01-01',
    ];

    foreach (['code', 'name', 'tax_type', 'effective_from'] as $required) {
        expect(fn () => $create->handle(array_diff_key($valid, [$required => true])))
            ->toThrow(InvalidTaxRule::class, "Tax rule field [{$required}] is required.");
    }

    expect(fn () => $create->handle([...$valid, 'rate' => -1]))
        ->toThrow(InvalidTaxRule::class, 'Tax rate must be between 0 and 100.')
        ->and(fn () => $create->handle([...$valid, 'rate' => 101]))
        ->toThrow(InvalidTaxRule::class, 'Tax rate must be between 0 and 100.')
        ->and(fn () => $create->handle([...$valid, 'rounding_scale' => -1]))
        ->toThrow(InvalidTaxRule::class, 'Rounding scale must be between 0 and 6.')
        ->and(fn () => $create->handle([...$valid, 'rounding_scale' => 7]))
        ->toThrow(InvalidTaxRule::class, 'Rounding scale must be between 0 and 6.')
        ->and(fn () => $create->handle([...$valid, 'effective_until' => '2025-12-31']))
        ->toThrow(InvalidTaxRule::class, 'Effective end must not precede effective start.');

    $rule = $create->handle($valid);

    expect(fn () => $create->handle($valid))
        ->toThrow(InvalidTaxRule::class, 'A tax rule already exists for this code, jurisdiction, and effective date.')
        ->and(fn () => $calculate->handle($rule, -0.01))
        ->toThrow(InvalidTaxRule::class, 'Taxable amount must not be negative.');
});
