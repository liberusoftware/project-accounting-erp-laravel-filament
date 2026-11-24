<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Liberu\Accounting\ProductAndServiceItems\Actions\SaveAccountingItem;
use Liberu\Accounting\ProductAndServiceItems\Enums\ItemKind;
use Liberu\Accounting\ProductAndServiceItems\Enums\ItemStatus;
use Liberu\Accounting\ProductAndServiceItems\Exceptions\InvalidItem;
use Liberu\Accounting\ProductAndServiceItems\Models\AccountingItem;
use Liberu\Accounting\ProductAndServiceItems\ProductAndServiceItemsServiceProvider;
use Liberu\Accounting\ProductAndServiceItems\Queries\FindAccountingItems;

it('boots the service provider and loads the items table', function (): void {
    expect(app()->getProvider(ProductAndServiceItemsServiceProvider::class))
        ->toBeInstanceOf(ProductAndServiceItemsServiceProvider::class);

    expect(Schema::hasTable('accounting_product_service_items'))->toBeTrue();
});

it('exposes item kind and item status enum cases', function (): void {
    expect(array_map(fn (ItemKind $c): string => $c->value, ItemKind::cases()))
        ->toBe(['item', 'service']);

    expect(array_map(fn (ItemStatus $c): string => $c->value, ItemStatus::cases()))
        ->toBe(['active', 'inactive', 'archived']);
});

it('saves, updates, and casts accounting items', function (): void {
    $action = app(SaveAccountingItem::class);

    $service = $action->handle([
        'team_id' => 10,
        'code' => '  SRV-001  ',
        'name' => '  Bookkeeping Retainer  ',
        'sales_price' => 250.5,
        'purchase_price' => 0,
        'currency' => 'GBP',
        'ecommerce_refs' => ['stripe' => 'prod_123'],
        'metadata' => ['taxable' => true],
    ]);

    expect($service)->toBeInstanceOf(AccountingItem::class)
        ->and($service->code)->toBe('SRV-001')
        ->and($service->name)->toBe('Bookkeeping Retainer')
        ->and($service->kind)->toBe(ItemKind::Service)
        ->and($service->status)->toBe(ItemStatus::Active)
        ->and((float) $service->sales_price)->toBe(250.5)
        ->and((float) $service->purchase_price)->toBe(0.0)
        ->and($service->ecommerce_refs)->toBe(['stripe' => 'prod_123'])
        ->and($service->metadata)->toBe(['taxable' => true]);

    // Upsert for same team_id + code updates in place
    $updated = $action->handle([
        'team_id' => 10,
        'code' => 'SRV-001',
        'name' => 'Advisory Retainer',
        'kind' => 'item',
        'status' => ItemStatus::Inactive,
        'sales_price' => 300,
    ]);

    expect($updated->id)->toBe($service->id)
        ->and($updated->name)->toBe('Advisory Retainer')
        ->and($updated->kind)->toBe(ItemKind::Item)
        ->and($updated->status)->toBe(ItemStatus::Inactive)
        ->and((float) $updated->sales_price)->toBe(300.0);

    // Different team_id creates a distinct record
    $otherTeamItem = $action->handle([
        'team_id' => 11,
        'code' => 'SRV-001',
        'name' => 'Team 11 Retainer',
        'kind' => 'service',
    ]);

    expect($otherTeamItem->id)->not->toBe($service->id);
});

it('validates required code/name and non-negative prices in SaveAccountingItem', function (): void {
    $action = app(SaveAccountingItem::class);

    expect(fn () => $action->handle(['code' => '   ', 'name' => 'Valid']))
        ->toThrow(InvalidItem::class, 'Code and name are required.');

    expect(fn () => $action->handle(['code' => 'ITEM-1', 'name' => '   ']))
        ->toThrow(InvalidItem::class, 'Code and name are required.');

    expect(fn () => $action->handle(['code' => 'ITEM-1', 'name' => 'Widget', 'purchase_price' => -0.01]))
        ->toThrow(InvalidItem::class, 'purchase_price cannot be negative.');

    expect(fn () => $action->handle(['code' => 'ITEM-1', 'name' => 'Widget', 'sales_price' => -5]))
        ->toThrow(InvalidItem::class, 'sales_price cannot be negative.');

    expect(fn () => $action->handle(['code' => 'ITEM-1', 'name' => 'Widget', 'kind' => 'invalid']))
        ->toThrow(ValueError::class);
});

it('searches only active accounting items ordered by code', function (): void {
    $query = app(FindAccountingItems::class);

    expect($query->search()->get())->toBeEmpty();

    $action = app(SaveAccountingItem::class);
    $action->handle(['code' => 'B-200', 'name' => 'Beta Hardware', 'kind' => 'item']);
    $action->handle(['code' => 'A-100', 'name' => 'Alpha Consulting', 'kind' => 'service']);
    $action->handle(['code' => 'C-300', 'name' => 'Gamma Archived', 'status' => ItemStatus::Archived]);
    $action->handle(['code' => 'D-400', 'name' => 'Delta Inactive', 'status' => ItemStatus::Inactive]);

    $allActive = $query->search('   ')->get();
    expect($allActive->pluck('code')->all())->toBe(['A-100', 'B-200']);

    $byCode = $query->search(' B-2 ')->get();
    expect($byCode->pluck('code')->all())->toBe(['B-200']);

    $byName = $query->search('Consulting')->get();
    expect($byName->pluck('code')->all())->toBe(['A-100']);

    expect($query->search('Gamma')->get())->toBeEmpty()
        ->and($query->search('NonExistent')->get())->toBeEmpty();
});
