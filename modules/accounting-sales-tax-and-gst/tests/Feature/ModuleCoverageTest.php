<?php

declare(strict_types=1);

use Liberu\Accounting\SalesTaxAndGst\Actions\ActivateSalesTaxRecord;
use Liberu\Accounting\SalesTaxAndGst\Actions\CloseSalesTaxRecord;
use Liberu\Accounting\SalesTaxAndGst\Actions\CreateSalesTaxRecord;
use Liberu\Accounting\SalesTaxAndGst\Enums\SalesTaxRecordType;
use Liberu\Accounting\SalesTaxAndGst\Enums\SalesTaxStatus;
use Liberu\Accounting\SalesTaxAndGst\Exceptions\InvalidSalesTax;
use Liberu\Accounting\SalesTaxAndGst\Models\SalesTaxRecord;
use Liberu\Accounting\SalesTaxAndGst\Queries\SalesTaxRecordQuery;
use Liberu\Accounting\SalesTaxAndGst\SalesTaxAndGstServiceProvider;

it('boots sales tax and gst service provider and covers enums and exception', function (): void {
    $provider = $this->app->register(SalesTaxAndGstServiceProvider::class, force: true);
    $provider->boot();

    expect($provider)->toBeInstanceOf(SalesTaxAndGstServiceProvider::class)
        ->and(array_column(SalesTaxRecordType::cases(), 'value'))->toBe(['registration', 'nexus', 'rule', 'liability', 'adjustment', 'return_period'])
        ->and(array_column(SalesTaxStatus::cases(), 'value'))->toBe(['draft', 'active', 'closed', 'archived'])
        ->and((new InvalidSalesTax('Sales tax error'))->getMessage())->toBe('Sales tax error');
});

it('creates, activates, closes, scopes, and queries sales tax records', function (): void {
    $query = app(SalesTaxRecordQuery::class);
    expect($query->paginate()->total())->toBe(0);

    $record = app(CreateSalesTaxRecord::class)->handle([
        'context_id' => 'org-us-1',
        'type' => SalesTaxRecordType::Liability,
        'jurisdiction' => 'US-CA',
        'origin' => 'US-CA-SF',
        'destination' => 'US-CA-LA',
        'rate' => 8.25,
        'taxable_base' => 1000.00,
        'period_start' => '2026-07-01',
        'period_end' => '2026-09-30',
        'metadata' => ['filing' => 'Q3'],
    ]);

    expect($record)->toBeInstanceOf(SalesTaxRecord::class)
        ->and($record->type)->toBe(SalesTaxRecordType::Liability)
        ->and($record->status)->toBe(SalesTaxStatus::Draft)
        ->and((float) $record->liability)->toBe(82.50)
        ->and($record->metadata)->toBe(['filing' => 'Q3']);

    $active = app(ActivateSalesTaxRecord::class)->handle($record);
    expect($active->status)->toBe(SalesTaxStatus::Active)
        ->and(SalesTaxRecord::query()->active()->count())->toBe(1);

    // Create a second record with string type and explicit liability/status
    $ruleRecord = app(CreateSalesTaxRecord::class)->handle([
        'context_id' => 'org-au-1',
        'type' => 'rule',
        'jurisdiction' => 'AU-GST',
        'rate' => 10,
        'taxable_base' => 500,
        'liability' => 50,
        'status' => SalesTaxStatus::Active,
        'period_start' => '2026-07-01',
        'period_end' => '2026-09-30',
    ]);

    expect($ruleRecord->type)->toBe(SalesTaxRecordType::Rule)
        ->and(SalesTaxRecord::query()->active()->count())->toBe(2);

    $closed = app(CloseSalesTaxRecord::class)->handle($active);
    expect($closed->status)->toBe(SalesTaxStatus::Closed)
        ->and(SalesTaxRecord::query()->active()->count())->toBe(1);

    $filtered = $query->paginate('org-us-1', 'liability', 'closed', 200);
    expect($filtered->total())->toBe(1)
        ->and($filtered->perPage())->toBe(100);

    $clampedMin = $query->paginate('org-au-1', 'rule', 'active', 0);
    expect($clampedMin->total())->toBe(1)
        ->and($clampedMin->perPage())->toBe(1);
});

it('validates sales tax record creation inputs', function (): void {
    $create = app(CreateSalesTaxRecord::class);
    $valid = [
        'context_id' => 'org-val',
        'type' => 'nexus',
        'jurisdiction' => 'US-NY',
        'period_start' => '2026-01-01',
        'period_end' => '2026-03-31',
    ];

    foreach (['context_id', 'type', 'jurisdiction', 'period_start', 'period_end'] as $required) {
        expect(fn () => $create->handle(array_diff_key($valid, [$required => true])))
            ->toThrow(InvalidSalesTax::class, "Sales tax field [{$required}] is required.");
    }

    expect(fn () => $create->handle([...$valid, 'type' => 'invalid_type']))
        ->toThrow(InvalidSalesTax::class, 'Unknown sales tax record type.')
        ->and(fn () => $create->handle([...$valid, 'rate' => -1]))
        ->toThrow(InvalidSalesTax::class, 'Tax rate must be between 0 and 100.')
        ->and(fn () => $create->handle([...$valid, 'rate' => 101]))
        ->toThrow(InvalidSalesTax::class, 'Tax rate must be between 0 and 100.')
        ->and(fn () => $create->handle([...$valid, 'taxable_base' => -10]))
        ->toThrow(InvalidSalesTax::class, 'Taxable base must not be negative.');
});
