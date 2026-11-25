<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;
use Liberu\Accounting\FixedAssets\Actions\AcquireAsset;
use Liberu\Accounting\FixedAssets\Actions\AddAssetComponent;
use Liberu\Accounting\FixedAssets\Actions\AddAssetDocument;
use Liberu\Accounting\FixedAssets\Actions\ArchiveAsset;
use Liberu\Accounting\FixedAssets\Actions\AssignAssetCustodian;
use Liberu\Accounting\FixedAssets\Actions\AssignAssetLocation;
use Liberu\Accounting\FixedAssets\Actions\CapitalizeAsset;
use Liberu\Accounting\FixedAssets\Actions\CreateCategory;
use Liberu\Accounting\FixedAssets\Actions\CreateCustodian;
use Liberu\Accounting\FixedAssets\Actions\CreateLocation;
use Liberu\Accounting\FixedAssets\Actions\DisposeAsset;
use Liberu\Accounting\FixedAssets\Actions\UpdateAsset;
use Liberu\Accounting\FixedAssets\Enums\AssetStatus;
use Liberu\Accounting\FixedAssets\Events\AssetAcquired;
use Liberu\Accounting\FixedAssets\Events\AssetArchived;
use Liberu\Accounting\FixedAssets\Events\AssetCapitalized;
use Liberu\Accounting\FixedAssets\Events\AssetComponentAdded;
use Liberu\Accounting\FixedAssets\Events\AssetCustodianAssigned;
use Liberu\Accounting\FixedAssets\Events\AssetDisposed;
use Liberu\Accounting\FixedAssets\Events\AssetDocumentAdded;
use Liberu\Accounting\FixedAssets\Events\AssetLocationAssigned;
use Liberu\Accounting\FixedAssets\Exceptions\AssetNotFound;
use Liberu\Accounting\FixedAssets\Exceptions\InvalidAsset;
use Liberu\Accounting\FixedAssets\FixedAssetsServiceProvider;
use Liberu\Accounting\FixedAssets\Models\Asset;
use Liberu\Accounting\FixedAssets\Models\AssetBook;
use Liberu\Accounting\FixedAssets\Models\AssetCategory;
use Liberu\Accounting\FixedAssets\Models\AssetComponent;
use Liberu\Accounting\FixedAssets\Models\AssetCustodian;
use Liberu\Accounting\FixedAssets\Models\AssetDocument;
use Liberu\Accounting\FixedAssets\Models\AssetLocation;
use Liberu\Accounting\FixedAssets\Policies\AssetPolicy;
use Liberu\Accounting\FixedAssets\Queries\AssetQuery;

it('boots fixed assets service provider and covers enum, exceptions, and policy', function (): void {
    $provider = $this->app->register(FixedAssetsServiceProvider::class, force: true);
    $provider->boot();

    expect($provider)->toBeInstanceOf(FixedAssetsServiceProvider::class)
        ->and(array_column(AssetStatus::cases(), 'value'))->toBe(['draft', 'acquired', 'capitalized', 'disposed', 'archived'])
        ->and((new AssetNotFound('Missing asset'))->getMessage())->toBe('Missing asset')
        ->and((new InvalidAsset('Invalid asset'))->getMessage())->toBe('Invalid asset');

    $policy = new AssetPolicy();
    $asset = new Asset(['team_id' => 7]);
    $unscopedAsset = new Asset(['team_id' => null]);

    $sameTeamUser = (object) ['current_team_id' => 7];
    $otherTeamUser = (object) ['current_team_id' => 8];
    $noTeamUser = (object) [];

    expect($policy->create($sameTeamUser))->toBeTrue()
        ->and($policy->create($noTeamUser))->toBeFalse()
        ->and($policy->create(null))->toBeFalse()
        ->and($policy->view($sameTeamUser, $asset))->toBeTrue()
        ->and($policy->view($otherTeamUser, $asset))->toBeFalse()
        ->and($policy->view($sameTeamUser, $unscopedAsset))->toBeFalse()
        ->and($policy->view(null, $asset))->toBeFalse()
        ->and($policy->update($sameTeamUser, $asset))->toBeTrue()
        ->and($policy->update($otherTeamUser, $asset))->toBeFalse()
        ->and($policy->capitalize($sameTeamUser, $asset))->toBeTrue()
        ->and($policy->capitalize($otherTeamUser, $asset))->toBeFalse()
        ->and($policy->dispose($sameTeamUser, $asset))->toBeTrue()
        ->and($policy->dispose($otherTeamUser, $asset))->toBeFalse();
});

it('executes full fixed asset lifecycle, relations, events, and queries', function (): void {
    Event::fake([
        AssetAcquired::class,
        AssetArchived::class,
        AssetCapitalized::class,
        AssetComponentAdded::class,
        AssetCustodianAssigned::class,
        AssetDisposed::class,
        AssetDocumentAdded::class,
        AssetLocationAssigned::class,
    ]);

    $category = app(CreateCategory::class)->handle([
        'team_id' => 10,
        'category_ref' => 'IT-EQUIP',
        'name' => 'IT Equipment',
        'asset_account_ref' => '1500',
        'depreciation_account_ref' => '6100',
        'useful_life_months' => 36,
        'depreciation_method' => 'straight_line',
        'metadata' => ['group' => 'hardware'],
    ]);

    $location = app(CreateLocation::class)->handle([
        'team_id' => 10,
        'location_ref' => 'HQ-1',
        'name' => 'Headquarters Floor 1',
        'metadata' => ['city' => 'London'],
    ]);

    $custodian = app(CreateCustodian::class)->handle([
        'team_id' => 10,
        'custodian_ref' => 'EMP-42',
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.test',
        'metadata' => ['department' => 'Engineering'],
    ]);

    $asset = app(AcquireAsset::class)->handle($category, [
        'team_id' => 10,
        'asset_ref' => 'FA-001',
        'name' => 'Build Server',
        'acquired_on' => '2026-01-15',
        'cost' => 5000.00,
        'salvage_value' => 500.00,
        'currency' => 'gbp',
        'metadata' => ['serial' => 'SN-1'],
    ]);

    expect($asset->status)->toBe(AssetStatus::Acquired)
        ->and($asset->currency)->toBe('GBP')
        ->and((float) $asset->cost)->toBe(5000.0)
        ->and((float) $asset->salvage_value)->toBe(500.0)
        ->and((float) $asset->net_book_value)->toBe(5000.0)
        ->and($asset->category->is($category))->toBeTrue()
        ->and($category->assets()->count())->toBe(1);

    Event::assertDispatched(AssetAcquired::class, fn (AssetAcquired $event): bool => $event->asset->is($asset));

    $updated = app(UpdateAsset::class)->handle($asset, [
        'asset_ref' => 'FA-001-REV',
        'name' => 'Build Server Pro',
        'currency' => 'usd',
        'metadata' => ['serial' => 'SN-1A'],
    ]);

    expect($updated->asset_ref)->toBe('FA-001-REV')
        ->and($updated->name)->toBe('Build Server Pro')
        ->and($updated->currency)->toBe('USD')
        ->and($updated->metadata)->toBe(['serial' => 'SN-1A']);

    $withLocation = app(AssignAssetLocation::class)->handle($updated, $location);
    expect($withLocation->location->is($location))->toBeTrue()
        ->and($withLocation->location_ref)->toBe('HQ-1')
        ->and($location->assets()->count())->toBe(1);
    Event::assertDispatched(AssetLocationAssigned::class, fn (AssetLocationAssigned $event): bool => $event->asset->is($withLocation) && $event->location->is($location));

    $withCustodian = app(AssignAssetCustodian::class)->handle($withLocation, $custodian);
    expect($withCustodian->custodian->is($custodian))->toBeTrue()
        ->and($withCustodian->custodian_ref)->toBe('EMP-42')
        ->and($custodian->assets()->count())->toBe(1);
    Event::assertDispatched(AssetCustodianAssigned::class, fn (AssetCustodianAssigned $event): bool => $event->asset->is($withCustodian) && $event->custodian->is($custodian));

    $capitalized = app(CapitalizeAsset::class)->handle($withCustodian, 'GAAP-MAIN');
    expect($capitalized->status)->toBe(AssetStatus::Capitalized)
        ->and($capitalized->capitalized_on)->not->toBeNull()
        ->and($capitalized->books()->count())->toBe(1);

    /** @var AssetBook $book */
    $book = $capitalized->books()->firstOrFail();
    expect($book->asset->is($capitalized))->toBeTrue()
        ->and((float) $book->cost)->toBe(5000.0);
    Event::assertDispatched(AssetCapitalized::class, fn (AssetCapitalized $event): bool => $event->asset->is($capitalized) && $event->bookReference === 'GAAP-MAIN');

    $component = app(AddAssetComponent::class)->handle($capitalized, [
        'component_ref' => 'GPU-1',
        'name' => 'Accelerator Card',
        'cost' => 1500.00,
        'useful_life_months' => 24,
        'metadata' => ['slot' => 'PCIe-1'],
    ]);

    expect($component)->toBeInstanceOf(AssetComponent::class)
        ->and($component->asset->is($capitalized))->toBeTrue()
        ->and((float) $capitalized->refresh()->cost)->toBe(6500.0)
        ->and((float) $capitalized->net_book_value)->toBe(6500.0)
        ->and((float) $book->refresh()->cost)->toBe(6500.0)
        ->and((float) $book->net_book_value)->toBe(6500.0);
    Event::assertDispatched(AssetComponentAdded::class, fn (AssetComponentAdded $event): bool => $event->asset->is($capitalized) && $event->component->is($component));

    $document = app(AddAssetDocument::class)->handle($capitalized, [
        'document_ref' => 'DOC-001',
        'kind' => 'invoice',
        'file_ref' => 's3://bucket/inv-001.pdf',
        'description' => 'Purchase invoice',
        'checksum' => 'sha256:abc',
        'attached_by' => 'user:1',
        'metadata' => ['pages' => 2],
    ]);

    expect($document)->toBeInstanceOf(AssetDocument::class)
        ->and($document->asset->is($capitalized))->toBeTrue()
        ->and($document->attached_at)->not->toBeNull();
    Event::assertDispatched(AssetDocumentAdded::class, fn (AssetDocumentAdded $event): bool => $event->asset->is($capitalized) && $event->document->is($document));

    $query = app(AssetQuery::class);
    $page = $query->paginate(10, AssetStatus::Capitalized, 200);
    expect($page->total())->toBe(1)
        ->and($page->perPage())->toBe(100);

    $minPage = $query->paginate(null, null, 0);
    expect($minPage->total())->toBe(1)
        ->and($minPage->perPage())->toBe(1);

    $summary = $query->registerSummary($capitalized->load(['books', 'documents']));
    expect($summary)->toBe([
        'asset_ref' => 'FA-001-REV',
        'cost' => 6500.0,
        'salvage_value' => 500.0,
        'net_book_value' => 6500.0,
        'status' => 'capitalized',
        'books' => 1,
        'documents' => 1,
    ]);

    $disposed = app(DisposeAsset::class)->handle($capitalized, 'End of lifecycle');
    expect($disposed->status)->toBe(AssetStatus::Disposed)
        ->and($disposed->metadata['disposal']['reason'])->toBe('End of lifecycle');
    Event::assertDispatched(AssetDisposed::class, fn (AssetDisposed $event): bool => $event->asset->is($disposed));

    $archived = app(ArchiveAsset::class)->handle($disposed);
    expect($archived->status)->toBe(AssetStatus::Archived);
    Event::assertDispatched(AssetArchived::class, fn (AssetArchived $event): bool => $event->asset->is($archived));
});

it('enforces validation, tenant checks, state transitions, and query exception handling', function (): void {
    $createCategory = app(CreateCategory::class);
    $validCategory = [
        'team_id' => 20,
        'category_ref' => 'VEH-01',
        'name' => 'Vehicles',
        'asset_account_ref' => '1600',
        'depreciation_account_ref' => '6200',
        'useful_life_months' => 60,
        'depreciation_method' => 'straight_line',
    ];

    foreach (['category_ref', 'name', 'asset_account_ref', 'depreciation_account_ref', 'useful_life_months', 'depreciation_method'] as $field) {
        expect(fn () => $createCategory->handle(array_diff_key($validCategory, [$field => true])))
            ->toThrow(InvalidAsset::class, "Missing category field [{$field}].");
    }

    expect(fn () => $createCategory->handle([...$validCategory, 'useful_life_months' => 0]))
        ->toThrow(InvalidAsset::class, 'Category reference and useful life are invalid.')
        ->and(fn () => $createCategory->handle([...$validCategory, 'category_ref' => 'BAD REF!']))
        ->toThrow(InvalidAsset::class, 'Category reference and useful life are invalid.');

    $category = $createCategory->handle($validCategory);
    expect(fn () => $createCategory->handle($validCategory))
        ->toThrow(InvalidAsset::class, 'The category reference is already in use.');

    // CreateLocation validations
    $createLocation = app(CreateLocation::class);
    expect(fn () => $createLocation->handle(['name' => 'Warehouse']))
        ->toThrow(InvalidAsset::class, 'Missing location field [location_ref].')
        ->and(fn () => $createLocation->handle(['location_ref' => 'WH-1']))
        ->toThrow(InvalidAsset::class, 'Missing location field [name].');

    $location = $createLocation->handle(['team_id' => 20, 'location_ref' => 'WH-1', 'name' => 'Warehouse']);
    expect(fn () => $createLocation->handle(['team_id' => 20, 'location_ref' => 'WH-1', 'name' => 'Warehouse 2']))
        ->toThrow(InvalidAsset::class, 'The location reference is already in use.');
    $otherLocation = $createLocation->handle(['team_id' => 21, 'location_ref' => 'WH-2', 'name' => 'Other Warehouse']);

    // CreateCustodian validations
    $createCustodian = app(CreateCustodian::class);
    expect(fn () => $createCustodian->handle(['name' => 'Grace']))
        ->toThrow(InvalidAsset::class, 'Missing custodian field [custodian_ref].')
        ->and(fn () => $createCustodian->handle(['custodian_ref' => 'C-1']))
        ->toThrow(InvalidAsset::class, 'Missing custodian field [name].');

    $custodian = $createCustodian->handle(['team_id' => 20, 'custodian_ref' => 'C-1', 'name' => 'Grace']);
    expect(fn () => $createCustodian->handle(['team_id' => 20, 'custodian_ref' => 'C-1', 'name' => 'Grace 2']))
        ->toThrow(InvalidAsset::class, 'The custodian reference is already in use.');
    $otherCustodian = $createCustodian->handle(['team_id' => 21, 'custodian_ref' => 'C-2', 'name' => 'Other']);

    // AcquireAsset validations
    $acquire = app(AcquireAsset::class);
    $validAsset = [
        'team_id' => 20,
        'asset_ref' => 'VAN-01',
        'name' => 'Delivery Van',
        'currency' => 'USD',
        'acquired_on' => '2026-02-01',
        'cost' => 25000,
        'salvage_value' => 2000,
    ];

    foreach (['asset_ref', 'name', 'currency', 'acquired_on'] as $field) {
        expect(fn () => $acquire->handle($category, array_diff_key($validAsset, [$field => true])))
            ->toThrow(InvalidAsset::class, "Missing asset field [{$field}].");
    }

    expect(fn () => $acquire->handle($category, [...$validAsset, 'cost' => 0]))
        ->toThrow(InvalidAsset::class, 'Asset cost and salvage value are invalid.')
        ->and(fn () => $acquire->handle($category, [...$validAsset, 'salvage_value' => -1]))
        ->toThrow(InvalidAsset::class, 'Asset cost and salvage value are invalid.')
        ->and(fn () => $acquire->handle($category, [...$validAsset, 'salvage_value' => 30000]))
        ->toThrow(InvalidAsset::class, 'Asset cost and salvage value are invalid.')
        ->and(fn () => $acquire->handle($category, [...$validAsset, 'team_id' => 99]))
        ->toThrow(InvalidAsset::class, 'The category belongs to a different team.');

    $asset1 = $acquire->handle($category, $validAsset);
    $asset2 = $acquire->handle($category, [...$validAsset, 'asset_ref' => 'VAN-02']);

    expect(fn () => $acquire->handle($category, $validAsset))
        ->toThrow(InvalidAsset::class, 'The asset reference is already in use.');

    // UpdateAsset validations
    $update = app(UpdateAsset::class);
    expect(fn () => $update->handle($asset1, ['asset_ref' => '   ']))
        ->toThrow(InvalidAsset::class, 'The asset reference cannot be empty.')
        ->and(fn () => $update->handle($asset1, ['currency' => 'US']))
        ->toThrow(InvalidAsset::class, 'Currency must be a three-letter ISO code.')
        ->and(fn () => $update->handle($asset1, ['asset_ref' => 'VAN-02']))
        ->toThrow(InvalidAsset::class, 'The asset reference is already in use.');

    // Tenant checks for location and custodian
    expect(fn () => app(AssignAssetLocation::class)->handle($asset1, $otherLocation))
        ->toThrow(InvalidAsset::class, 'The location belongs to a different team.')
        ->and(fn () => app(AssignAssetCustodian::class)->handle($asset1, $otherCustodian))
        ->toThrow(InvalidAsset::class, 'The custodian belongs to a different team.');

    // CapitalizeAsset validations
    $capitalize = app(CapitalizeAsset::class);
    expect(fn () => $capitalize->handle($asset1, '   '))
        ->toThrow(InvalidAsset::class, 'Asset book reference is required.');

    $asset1->books()->create(['book_ref' => 'DUP-BOOK', 'cost' => 100, 'net_book_value' => 100]);
    expect(fn () => $capitalize->handle($asset1, 'DUP-BOOK'))
        ->toThrow(InvalidAsset::class, 'The asset book reference is already in use.');

    // Dispose and Archive state checks before capitalization
    expect(fn () => app(DisposeAsset::class)->handle($asset1, 'Sold'))
        ->toThrow(InvalidAsset::class, 'Only capitalized assets can be disposed.')
        ->and(fn () => app(ArchiveAsset::class)->handle($asset1))
        ->toThrow(InvalidAsset::class, 'Only disposed assets can be archived.');

    $capitalized = $capitalize->handle($asset1, 'TAX-BOOK');
    expect(fn () => $capitalize->handle($capitalized, 'ANOTHER-BOOK'))
        ->toThrow(InvalidAsset::class, 'Only acquired assets can be capitalized.');

    // AddAssetComponent validations
    $addComponent = app(AddAssetComponent::class);
    $validComp = ['component_ref' => 'ENG-1', 'name' => 'Engine', 'cost' => 4000, 'useful_life_months' => 24];
    foreach (['component_ref', 'name', 'cost', 'useful_life_months'] as $field) {
        expect(fn () => $addComponent->handle($capitalized, array_diff_key($validComp, [$field => true])))
            ->toThrow(InvalidAsset::class, "Missing component field [{$field}].");
    }
    expect(fn () => $addComponent->handle($capitalized, [...$validComp, 'cost' => -10]))
        ->toThrow(InvalidAsset::class, 'Component cost and useful life must be positive.')
        ->and(fn () => $addComponent->handle($capitalized, [...$validComp, 'useful_life_months' => 0]))
        ->toThrow(InvalidAsset::class, 'Component cost and useful life must be positive.');

    $addComponent->handle($capitalized, $validComp);
    expect(fn () => $addComponent->handle($capitalized, $validComp))
        ->toThrow(InvalidAsset::class, 'The component reference is already in use for this asset.');

    // AddAssetDocument validations
    $addDoc = app(AddAssetDocument::class);
    $validDoc = ['document_ref' => 'D-1', 'kind' => 'title', 'file_ref' => 'f.pdf', 'attached_by' => 'admin'];
    foreach (['document_ref', 'kind', 'file_ref', 'attached_by'] as $field) {
        expect(fn () => $addDoc->handle($capitalized, array_diff_key($validDoc, [$field => true])))
            ->toThrow(InvalidAsset::class, "Missing document field [{$field}].");
    }
    $addDoc->handle($capitalized, $validDoc);
    expect(fn () => $addDoc->handle($capitalized, $validDoc))
        ->toThrow(InvalidAsset::class, 'The document reference is already attached to this asset.');

    // DisposeAsset blank reason & closed asset component check
    expect(fn () => app(DisposeAsset::class)->handle($capitalized, '   '))
        ->toThrow(InvalidAsset::class, 'A disposal reason is required.');

    $disposed = app(DisposeAsset::class)->handle($capitalized, 'Sold');
    expect(fn () => $addComponent->handle($disposed, [...$validComp, 'component_ref' => 'ENG-2']))
        ->toThrow(InvalidAsset::class, 'Components cannot be added to a closed asset.');

    // QueryException catch blocks across create/acquire actions
    $dbFailure = static fn (): never => throw new QueryException('sqlite', 'insert', [], new RuntimeException('db error'));

    try {
        AssetCategory::creating($dbFailure);
        expect(fn () => $createCategory->handle([...$validCategory, 'category_ref' => 'CAT-ERR']))
            ->toThrow(InvalidAsset::class, 'The category could not be created.');
    } finally {
        AssetCategory::flushEventListeners();
    }

    try {
        AssetLocation::creating($dbFailure);
        expect(fn () => $createLocation->handle(['team_id' => 20, 'location_ref' => 'LOC-ERR', 'name' => 'Err']))
            ->toThrow(InvalidAsset::class, 'The location could not be created.');
    } finally {
        AssetLocation::flushEventListeners();
    }

    try {
        AssetCustodian::creating($dbFailure);
        expect(fn () => $createCustodian->handle(['team_id' => 20, 'custodian_ref' => 'CUS-ERR', 'name' => 'Err']))
            ->toThrow(InvalidAsset::class, 'The custodian could not be created.');
    } finally {
        AssetCustodian::flushEventListeners();
    }

    try {
        Asset::creating($dbFailure);
        expect(fn () => $acquire->handle($category, [...$validAsset, 'asset_ref' => 'VAN-ERR']))
            ->toThrow(InvalidAsset::class, 'The asset could not be acquired.');
    } finally {
        Asset::flushEventListeners();
    }

    // Keep $location, $custodian, $asset2 referenced cleanly
    expect($location->location_ref)->toBe('WH-1')
        ->and($custodian->custodian_ref)->toBe('C-1')
        ->and($asset2->asset_ref)->toBe('VAN-02');
});
