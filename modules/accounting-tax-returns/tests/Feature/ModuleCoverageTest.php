<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Liberu\Accounting\TaxReturns\Actions\AmendTaxReturn;
use Liberu\Accounting\TaxReturns\Actions\CreateTaxReturn;
use Liberu\Accounting\TaxReturns\Actions\SubmitTaxReturn;
use Liberu\Accounting\TaxReturns\Enums\TaxReturnStatus;
use Liberu\Accounting\TaxReturns\Exceptions\InvalidTaxReturn;
use Liberu\Accounting\TaxReturns\Models\TaxReturn;
use Liberu\Accounting\TaxReturns\Models\TaxReturnLine;
use Liberu\Accounting\TaxReturns\TaxReturnsServiceProvider;

it('registers and boots the tax returns service provider and tables', function (): void {
    expect(app()->getProvider(TaxReturnsServiceProvider::class))->toBeInstanceOf(TaxReturnsServiceProvider::class);
    expect(Schema::hasTable('accounting_tax_returns'))->toBeTrue();
    expect(Schema::hasTable('accounting_tax_return_lines'))->toBeTrue();
});

it('exposes all tax return status enum cases', function (): void {
    expect(array_map(static fn (TaxReturnStatus $case): string => $case->value, TaxReturnStatus::cases()))
        ->toBe(['draft', 'ready', 'submitted', 'accepted', 'rejected', 'amended']);
});

it('creates a tax return with defaults and explicit status and validates required period fields', function (): void {
    $create = app(CreateTaxReturn::class);

    $draft = $create->handle([
        'team_id' => 1,
        'tax_type' => 'corporate',
        'jurisdiction' => 'GB',
        'period_start' => '2026-01-01',
        'period_end' => '2026-03-31',
        'due_on' => '2026-04-30',
        'metadata' => ['box_count' => 3],
    ]);

    expect($draft)->toBeInstanceOf(TaxReturn::class)
        ->and($draft->status)->toBe(TaxReturnStatus::Draft)
        ->and($draft->period_start->toDateString())->toBe('2026-01-01')
        ->and($draft->period_end->toDateString())->toBe('2026-03-31')
        ->and($draft->due_on->toDateString())->toBe('2026-04-30')
        ->and($draft->metadata)->toBe(['box_count' => 3]);

    $ready = $create->handle([
        'team_id' => 1,
        'tax_type' => 'sales',
        'jurisdiction' => 'US-CA',
        'period_start' => '2026-04-01',
        'period_end' => '2026-06-30',
        'status' => TaxReturnStatus::Ready,
    ]);

    expect($ready->status)->toBe(TaxReturnStatus::Ready);

    $invalidPayloads = [
        ['team_id' => 1, 'jurisdiction' => 'GB', 'period_start' => '2026-01-01', 'period_end' => '2026-03-31'],
        ['team_id' => 1, 'tax_type' => 'vat', 'period_start' => '2026-01-01', 'period_end' => '2026-03-31'],
        ['team_id' => 1, 'tax_type' => 'vat', 'jurisdiction' => 'GB', 'period_end' => '2026-03-31'],
        ['team_id' => 1, 'tax_type' => 'vat', 'jurisdiction' => 'GB', 'period_start' => '2026-01-01'],
        ['team_id' => 1, 'tax_type' => 'vat', 'jurisdiction' => 'GB', 'period_start' => '2026-04-01', 'period_end' => '2026-03-01'],
    ];

    foreach ($invalidPayloads as $payload) {
        expect(fn () => $create->handle($payload))
            ->toThrow(InvalidTaxReturn::class, 'Tax type, jurisdiction, and a valid return period are required.');
    }
});

it('manages tax return lines relationships and casts', function (): void {
    $taxReturn = app(CreateTaxReturn::class)->handle([
        'team_id' => 2,
        'tax_type' => 'payroll',
        'jurisdiction' => 'DE',
        'period_start' => '2026-01-01',
        'period_end' => '2026-01-31',
    ]);

    $line = $taxReturn->lines()->create([
        'team_id' => 2,
        'code' => 'BOX_1',
        'amount' => '1234.560000',
        'currency' => 'EUR',
        'metadata' => ['source' => 'payroll'],
    ]);

    expect($line)->toBeInstanceOf(TaxReturnLine::class)
        ->and($line->amount)->toBe('1234.560000')
        ->and($line->currency)->toBe('EUR')
        ->and($line->metadata)->toBe(['source' => 'payroll'])
        ->and($line->taxReturn->is($taxReturn))->toBeTrue()
        ->and($taxReturn->lines()->count())->toBe(1);
});

it('submits draft and ready tax returns and rejects invalid states', function (): void {
    $create = app(CreateTaxReturn::class);
    $submit = app(SubmitTaxReturn::class);

    $draft = $create->handle([
        'team_id' => 3,
        'tax_type' => 'vat',
        'jurisdiction' => 'UK',
        'period_start' => '2026-01-01',
        'period_end' => '2026-03-31',
    ]);

    $submittedDraft = $submit->handle($draft, 'EXT-REF-001');
    expect($submittedDraft->status)->toBe(TaxReturnStatus::Submitted)
        ->and($submittedDraft->external_reference)->toBe('EXT-REF-001')
        ->and($submittedDraft->submitted_at)->not->toBeNull();

    $ready = $create->handle([
        'team_id' => 3,
        'tax_type' => 'vat',
        'jurisdiction' => 'UK',
        'period_start' => '2026-04-01',
        'period_end' => '2026-06-30',
        'status' => TaxReturnStatus::Ready,
    ]);

    $submittedReady = $submit->handle($ready, 'EXT-REF-002');
    expect($submittedReady->status)->toBe(TaxReturnStatus::Submitted)
        ->and($submittedReady->external_reference)->toBe('EXT-REF-002');

    expect(fn () => $submit->handle($submittedReady, 'EXT-REF-003'))
        ->toThrow(InvalidTaxReturn::class, 'Only draft or ready returns can be submitted.');

    $submittedReady->update(['status' => TaxReturnStatus::Accepted]);
    expect(fn () => $submit->handle($submittedReady->refresh(), 'EXT-REF-004'))
        ->toThrow(InvalidTaxReturn::class);
});

it('amends only accepted tax returns', function (): void {
    $create = app(CreateTaxReturn::class);
    $amend = app(AmendTaxReturn::class);

    $draft = $create->handle([
        'team_id' => 4,
        'tax_type' => 'corporate',
        'jurisdiction' => 'FR',
        'period_start' => '2026-01-01',
        'period_end' => '2026-12-31',
    ]);

    expect(fn () => $amend->handle($draft))
        ->toThrow(InvalidTaxReturn::class, 'Only accepted returns can be amended.');

    $draft->update(['status' => TaxReturnStatus::Accepted]);

    $amended = $amend->handle($draft->refresh());
    expect($amended->status)->toBe(TaxReturnStatus::Amended);

    expect(fn () => $amend->handle($amended))
        ->toThrow(InvalidTaxReturn::class, 'Only accepted returns can be amended.');
});
