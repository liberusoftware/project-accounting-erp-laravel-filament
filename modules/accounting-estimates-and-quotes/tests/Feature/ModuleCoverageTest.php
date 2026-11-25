<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Liberu\Accounting\EstimatesAndQuotes\Actions\AddEstimateItem;
use Liberu\Accounting\EstimatesAndQuotes\Actions\ConvertEstimate;
use Liberu\Accounting\EstimatesAndQuotes\Actions\CreateEstimate;
use Liberu\Accounting\EstimatesAndQuotes\Actions\DecideEstimate;
use Liberu\Accounting\EstimatesAndQuotes\Actions\ExpireEstimate;
use Liberu\Accounting\EstimatesAndQuotes\Actions\SendEstimate;
use Liberu\Accounting\EstimatesAndQuotes\Enums\EstimateStatus;
use Liberu\Accounting\EstimatesAndQuotes\EstimatesAndQuotesServiceProvider;
use Liberu\Accounting\EstimatesAndQuotes\Events\EstimateLifecycleChanged;
use Liberu\Accounting\EstimatesAndQuotes\Exceptions\InvalidEstimate;
use Liberu\Accounting\EstimatesAndQuotes\Models\Estimate;
use Liberu\Accounting\EstimatesAndQuotes\Models\EstimateHistory;
use Liberu\Accounting\EstimatesAndQuotes\Models\EstimateItem;
use Liberu\Accounting\EstimatesAndQuotes\Models\EstimateVersion;
use Liberu\Accounting\EstimatesAndQuotes\Queries\EstimateQuery;

it('boots estimates and quotes service provider and covers enum and exception', function (): void {
    $provider = $this->app->register(EstimatesAndQuotesServiceProvider::class, force: true);
    $provider->boot();

    expect($provider)->toBeInstanceOf(EstimatesAndQuotesServiceProvider::class)
        ->and(array_column(EstimateStatus::cases(), 'value'))->toBe([
            'draft',
            'sent',
            'accepted',
            'declined',
            'expired',
            'converted',
        ])
        ->and((new InvalidEstimate('Estimate error'))->getMessage())->toBe('Estimate error');
});

it('executes full estimate lifecycle for acceptance, conversion, decline, expiry, and queries', function (): void {
    Event::fake([EstimateLifecycleChanged::class]);

    $query = app(EstimateQuery::class);
    expect($query->paginate()->total())->toBe(0);

    $estimate = app(CreateEstimate::class)->handle([
        'legal_entity_id' => 4,
        'customer_ref' => 'CUST-01',
        'quote_ref' => 'QT-2026-001',
        'name' => 'Website Redesign',
        'currency' => 'gbp',
        'issue_date' => '2026-03-01',
        'expires_on' => '2026-03-31',
        'terms' => 'Net 30',
        'brand' => ['color' => '#0ea5e9'],
        'metadata' => ['owner' => 'sales'],
        'created_by' => 'user:1',
    ]);

    expect($estimate)->toBeInstanceOf(Estimate::class)
        ->and($estimate->status)->toBe(EstimateStatus::Draft)
        ->and($estimate->currency)->toBe('GBP')
        ->and($estimate->version)->toBe(1)
        ->and($estimate->brand)->toBe(['color' => '#0ea5e9'])
        ->and($estimate->metadata)->toBe(['owner' => 'sales'])
        ->and($estimate->versions()->count())->toBe(1)
        ->and($estimate->history()->count())->toBe(1);

    $version = $estimate->versions()->first();
    $history = $estimate->history()->first();
    expect($version)->toBeInstanceOf(EstimateVersion::class)
        ->and($version->estimate->is($estimate))->toBeTrue()
        ->and($history)->toBeInstanceOf(EstimateHistory::class)
        ->and($history->estimate->is($estimate))->toBeTrue();

    $item1 = app(AddEstimateItem::class)->handle($estimate, [
        'item_ref' => 'SKU-1',
        'description' => 'Design phase',
        'quantity' => 2.5,
        'unit_price' => 100.00,
        'tax_rate' => 0.2,
        'metadata' => ['phase' => 1],
    ]);
    $item2 = app(AddEstimateItem::class)->handle($estimate, [
        'description' => 'Discovery workshop',
        'quantity' => 1,
        'unit_price' => 0,
    ]);

    expect($item1)->toBeInstanceOf(EstimateItem::class)
        ->and($item1->estimate->is($estimate))->toBeTrue()
        ->and((float) $item1->amount)->toBe(250.0)
        ->and($item2->estimate->is($estimate))->toBeTrue()
        ->and($query->total($estimate))->toBe(250.0);

    $sent = app(SendEstimate::class)->handle($estimate, 'user:2');
    expect($sent->status)->toBe(EstimateStatus::Sent);

    $accepted = app(DecideEstimate::class)->handle($sent, true, null, 'cust:1');
    expect($accepted->status)->toBe(EstimateStatus::Accepted)
        ->and($accepted->accepted_at)->not->toBeNull()
        ->and($accepted->declined_reason)->toBeNull();

    $converted = app(ConvertEstimate::class)->handle($accepted, 'INV-2026-999', 'user:3');
    expect($converted->status)->toBe(EstimateStatus::Converted)
        ->and($converted->converted_ref)->toBe('INV-2026-999')
        ->and($converted->history()->count())->toBe(4);

    // Declined flow
    $estimate2 = app(CreateEstimate::class)->handle([
        'legal_entity_id' => 5,
        'customer_ref' => 'CUST-02',
        'quote_ref' => 'QT-2026-002',
        'name' => 'Mobile App',
        'currency' => 'usd',
        'issue_date' => '2026-03-05',
    ]);
    app(AddEstimateItem::class)->handle($estimate2, [
        'description' => 'Prototype',
        'quantity' => 1,
        'unit_price' => 500,
    ]);
    $estimate2 = app(SendEstimate::class)->handle($estimate2);
    $declined = app(DecideEstimate::class)->handle($estimate2, false, 'Over budget', 'cust:2');
    expect($declined->status)->toBe(EstimateStatus::Declined)
        ->and($declined->declined_reason)->toBe('Over budget');

    // Expiry flow (past expires_on)
    $estimate3 = app(CreateEstimate::class)->handle([
        'legal_entity_id' => 5,
        'customer_ref' => 'CUST-03',
        'quote_ref' => 'QT-2026-003',
        'name' => 'Expired Quote',
        'currency' => 'eur',
        'issue_date' => '2020-01-01',
        'expires_on' => '2020-01-15',
    ]);
    $expired = app(ExpireEstimate::class)->handle($estimate3, 'system');
    expect($expired->status)->toBe(EstimateStatus::Expired);

    Event::assertDispatched(EstimateLifecycleChanged::class, function (EstimateLifecycleChanged $e) use ($converted): bool {
        return $e->estimate->is($converted) && $e->event === 'converted' && $e->actorReference === 'user:3';
    });

    // Query filters and perPage clamping
    $allPage = $query->paginate(null, null, 200);
    expect($allPage->total())->toBe(3)
        ->and($allPage->perPage())->toBe(100);

    $filteredPage = $query->paginate(4, EstimateStatus::Converted, 0);
    expect($filteredPage->total())->toBe(1)
        ->and($filteredPage->perPage())->toBe(1)
        ->and($filteredPage->items()[0]->is($converted))->toBeTrue();
});

it('enforces validation and state transitions across estimate actions', function (): void {
    $create = app(CreateEstimate::class);
    $valid = [
        'legal_entity_id' => 1,
        'customer_ref' => 'CUST-ERR',
        'quote_ref' => 'QT-ERR-1',
        'name' => 'Validation Quote',
        'currency' => 'USD',
        'issue_date' => '2026-05-01',
        'expires_on' => '2099-05-31',
    ];

    foreach (['legal_entity_id', 'customer_ref', 'quote_ref', 'name', 'currency', 'issue_date'] as $required) {
        expect(fn () => $create->handle(array_diff_key($valid, [$required => true])))
            ->toThrow(InvalidEstimate::class, "Missing estimate field [{$required}].");
    }

    expect(fn () => $create->handle([...$valid, 'currency' => 'US']))
        ->toThrow(InvalidEstimate::class, 'currency or dates are invalid')
        ->and(fn () => $create->handle([...$valid, 'expires_on' => '2026-04-01']))
        ->toThrow(InvalidEstimate::class, 'currency or dates are invalid');

    $draft = $create->handle($valid);

    $addItem = app(AddEstimateItem::class);
    $validItem = [
        'description' => 'Advisory',
        'quantity' => 2,
        'unit_price' => 150,
    ];

    foreach (['description', 'quantity', 'unit_price'] as $required) {
        expect(fn () => $addItem->handle($draft, array_diff_key($validItem, [$required => true])))
            ->toThrow(InvalidEstimate::class, "Missing item field [{$required}].");
    }

    expect(fn () => $addItem->handle($draft, [...$validItem, 'quantity' => 0]))
        ->toThrow(InvalidEstimate::class, 'quantity must be positive')
        ->and(fn () => $addItem->handle($draft, [...$validItem, 'unit_price' => -1]))
        ->toThrow(InvalidEstimate::class, 'price cannot be negative');

    // Invalid state transitions from Draft (with 0 items and future expires_on)
    expect(fn () => app(SendEstimate::class)->handle($draft))->toThrow(InvalidEstimate::class, 'draft estimates with items')
        ->and(fn () => app(DecideEstimate::class)->handle($draft, true))->toThrow(InvalidEstimate::class, 'Only sent estimates')
        ->and(fn () => app(ConvertEstimate::class)->handle($draft, 'INV-1'))->toThrow(InvalidEstimate::class, 'Only accepted estimates')
        ->and(fn () => app(ExpireEstimate::class)->handle($draft))->toThrow(InvalidEstimate::class, 'expired draft or sent estimate');

    $addItem->handle($draft, $validItem);
    $sent = app(SendEstimate::class)->handle($draft);

    expect(fn () => $addItem->handle($sent, $validItem))->toThrow(InvalidEstimate::class, 'Only draft estimates')
        ->and(fn () => app(SendEstimate::class)->handle($sent))->toThrow(InvalidEstimate::class, 'draft estimates with items')
        ->and(fn () => app(DecideEstimate::class)->handle($sent, false, ''))->toThrow(InvalidEstimate::class, 'decline reason is required');

    $accepted = app(DecideEstimate::class)->handle($sent, true);
    expect(fn () => app(ConvertEstimate::class)->handle($accepted, ''))
        ->toThrow(InvalidEstimate::class, 'Only accepted estimates');

    // Already-converted guard on an accepted estimate with converted_ref already set
    $accepted->update(['converted_ref' => 'INV-ALREADY']);
    expect(fn () => app(ConvertEstimate::class)->handle($accepted->refresh(), 'INV-NEW'))
        ->toThrow(InvalidEstimate::class, 'already been converted');
});
