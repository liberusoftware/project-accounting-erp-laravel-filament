<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Liberu\Accounting\Dimensions\Actions\AllocateDimensions;
use Liberu\Accounting\Dimensions\Actions\SaveDimension;
use Liberu\Accounting\Dimensions\Actions\SaveDimensionValue;
use Liberu\Accounting\Dimensions\Actions\ValidateDimensions;
use Liberu\Accounting\Dimensions\DimensionsServiceProvider;
use Liberu\Accounting\Dimensions\Enums\DimensionKind;
use Liberu\Accounting\Dimensions\Exceptions\InvalidDimension;
use Liberu\Accounting\Dimensions\Models\Dimension;
use Liberu\Accounting\Dimensions\Models\DimensionAllocation;
use Liberu\Accounting\Dimensions\Models\DimensionValue;
use Liberu\Accounting\Dimensions\Queries\DimensionalBalances;

it('registers and boots the dimensions service provider and migrations', function (): void {
    $provider = app()->getProvider(DimensionsServiceProvider::class)
        ?? new DimensionsServiceProvider(app());

    $provider->register();
    $provider->boot();

    expect($provider)->toBeInstanceOf(DimensionsServiceProvider::class)
        ->and(Schema::hasTable('accounting_dimensions'))->toBeTrue()
        ->and(Schema::hasTable('accounting_dimension_values'))->toBeTrue()
        ->and(Schema::hasTable('accounting_dimension_allocations'))->toBeTrue();
});

it('exposes dimension kind enum and invalid dimension exception', function (): void {
    expect(array_map(static fn (DimensionKind $k): string => $k->value, DimensionKind::cases()))
        ->toBe([
            'class',
            'location',
            'department',
            'cost_center',
            'profit_center',
            'project',
            'tag',
        ]);

    $exception = new InvalidDimension('Invalid dimension state.');
    expect($exception)->toBeInstanceOf(DomainException::class)
        ->and($exception->getMessage())->toBe('Invalid dimension state.');
});

it('creates, updates, and validates dimensions via SaveDimension', function (): void {
    $saveDimension = app(SaveDimension::class);

    $dimension = $saveDimension->handle([
        'code' => 'DEPT',
        'name' => 'Department',
        'kind' => DimensionKind::Department->value,
        'description' => 'Organizational department',
        'is_required' => true,
        'is_active' => true,
        'metadata' => ['owner' => 'finance'],
    ]);

    expect($dimension)->toBeInstanceOf(Dimension::class)
        ->and($dimension->code)->toBe('DEPT')
        ->and($dimension->name)->toBe('Department')
        ->and($dimension->kind)->toBe(DimensionKind::Department)
        ->and($dimension->description)->toBe('Organizational department')
        ->and($dimension->is_required)->toBeTrue()
        ->and($dimension->is_active)->toBeTrue()
        ->and($dimension->metadata)->toBe(['owner' => 'finance']);

    // Update existing dimension inheriting kind and code.
    $updated = $saveDimension->handle([
        'name' => 'Business Department',
        'is_required' => false,
    ], $dimension);

    expect($updated->id)->toBe($dimension->id)
        ->and($updated->code)->toBe('DEPT')
        ->and($updated->name)->toBe('Business Department')
        ->and($updated->kind)->toBe(DimensionKind::Department)
        ->and($updated->is_required)->toBeFalse();

    // Duplicate (kind, code) on create throws.
    expect(fn () => $saveDimension->handle([
        'code' => 'DEPT',
        'name' => 'Duplicate Department',
        'kind' => DimensionKind::Department->value,
    ]))->toThrow(InvalidDimension::class, 'The dimension code is already in use for this kind.');

    // Duplicate (kind, code) on update throws.
    $other = $saveDimension->handle([
        'code' => 'DEPT-2',
        'name' => 'Secondary Department',
        'kind' => DimensionKind::Department->value,
    ]);
    expect(fn () => $saveDimension->handle(['code' => 'DEPT'], $other))
        ->toThrow(InvalidDimension::class, 'The dimension code is already in use for this kind.');

    // Same code under a different kind is allowed.
    $location = $saveDimension->handle([
        'code' => 'DEPT',
        'name' => 'Location Code',
        'kind' => DimensionKind::Location->value,
    ]);
    expect($location->kind)->toBe(DimensionKind::Location);
});

it('rejects invalid dimension inputs in SaveDimension', function (array $payload): void {
    expect(fn () => app(SaveDimension::class)->handle($payload))
        ->toThrow(InvalidDimension::class, 'A dimension requires a valid kind, code, and name.');
})->with([
    'missing kind' => [['code' => 'A', 'name' => 'Alpha']],
    'invalid kind' => [['kind' => 'unknown_kind', 'code' => 'A', 'name' => 'Alpha']],
    'blank code' => [['kind' => 'department', 'code' => '', 'name' => 'Alpha']],
    'blank name' => [['kind' => 'department', 'code' => 'A', 'name' => '   ']],
]);

it('creates, updates, and links hierarchical dimension values via SaveDimensionValue', function (): void {
    $saveDimension = app(SaveDimension::class);
    $saveValue = app(SaveDimensionValue::class);

    $inactiveDimension = $saveDimension->handle([
        'code' => 'LOC-INACTIVE',
        'name' => 'Inactive Location',
        'kind' => DimensionKind::Location->value,
        'is_active' => false,
    ]);

    expect(fn () => $saveValue->handle($inactiveDimension, [
        'code' => 'LON',
        'name' => 'London',
    ]))->toThrow(InvalidDimension::class, 'Cannot add a value to an inactive dimension.');

    $dimension = $saveDimension->handle([
        'code' => 'CC',
        'name' => 'Cost Center',
        'kind' => DimensionKind::CostCenter->value,
    ]);

    $parent = $saveValue->handle($dimension, [
        'code' => 'ENG',
        'name' => 'Engineering',
        'is_active' => true,
        'metadata' => ['region' => 'EMEA'],
    ]);

    $child = $saveValue->handle($dimension, [
        'code' => 'ENG-CORE',
        'name' => 'Core Engineering',
        'parent_id' => $parent->id,
    ]);

    expect($parent)->toBeInstanceOf(DimensionValue::class)
        ->and($parent->is_active)->toBeTrue()
        ->and($parent->metadata)->toBe(['region' => 'EMEA'])
        ->and($parent->dimension->is($dimension))->toBeTrue()
        ->and($child->parent->is($parent))->toBeTrue()
        ->and($dimension->values()->count())->toBe(2);

    // Duplicate value code throws.
    expect(fn () => $saveValue->handle($dimension, [
        'code' => 'ENG',
        'name' => 'Engineering Dup',
    ]))->toThrow(InvalidDimension::class, 'The dimension value code is already in use.');

    // Update existing value keeping its own code succeeds.
    $updated = $saveValue->handle($dimension, [
        'code' => 'ENG',
        'name' => 'Platform Engineering',
    ], $parent);
    expect($updated->id)->toBe($parent->id)
        ->and($updated->name)->toBe('Platform Engineering');

    // Update colliding with another value's code throws.
    expect(fn () => $saveValue->handle($dimension, [
        'code' => 'ENG',
        'name' => 'Collision',
    ], $child))->toThrow(InvalidDimension::class, 'The dimension value code is already in use.');
});

it('validates required, active, and multi-value dimensions via ValidateDimensions', function (): void {
    $saveDimension = app(SaveDimension::class);
    $saveValue = app(SaveDimensionValue::class);
    $validate = app(ValidateDimensions::class);

    $dept = $saveDimension->handle([
        'code' => 'DEPT',
        'name' => 'Department',
        'kind' => DimensionKind::Department->value,
        'is_required' => true,
    ]);
    $saveValue->handle($dept, ['code' => 'FIN', 'name' => 'Finance', 'is_active' => true]);
    $saveValue->handle($dept, ['code' => 'OLD', 'name' => 'Legacy', 'is_active' => false]);

    $tags = $saveDimension->handle([
        'code' => 'TAG',
        'name' => 'Tags',
        'kind' => DimensionKind::Tag->value,
        'is_required' => false,
    ]);
    $saveValue->handle($tags, ['code' => 'Q1', 'name' => 'Quarter 1']);
    $saveValue->handle($tags, ['code' => 'CAPEX', 'name' => 'Capital Expense']);

    // Inactive required dimension is ignored.
    $saveDimension->handle([
        'code' => 'PROJ',
        'name' => 'Project',
        'kind' => DimensionKind::Project->value,
        'is_required' => true,
        'is_active' => false,
    ]);

    // Missing required dimension throws.
    expect(fn () => $validate->handle(['tag' => ['Q1']]))
        ->toThrow(InvalidDimension::class, 'The department dimension is required.');

    // Unknown value throws.
    expect(fn () => $validate->handle(['department' => 'UNKNOWN']))
        ->toThrow(InvalidDimension::class, 'Unknown or inactive department dimension value: UNKNOWN.');

    // Inactive value throws.
    expect(fn () => $validate->handle(['department' => 'OLD']))
        ->toThrow(InvalidDimension::class, 'Unknown or inactive department dimension value: OLD.');

    // Valid single and array values pass and are returned unchanged.
    $input = ['department' => 'FIN', 'tag' => ['Q1', 'CAPEX']];
    expect($validate->handle($input))->toBe($input);
});

it('allocates amounts across dimensions and aggregates balances via DimensionalBalances', function (): void {
    $saveDimension = app(SaveDimension::class);
    $saveValue = app(SaveDimensionValue::class);
    $allocate = app(AllocateDimensions::class);
    $balances = app(DimensionalBalances::class);

    expect($balances->handle())->toBe([]);

    $dept = $saveDimension->handle([
        'code' => 'DEPT',
        'name' => 'Department',
        'kind' => DimensionKind::Department->value,
        'is_required' => true,
    ]);
    $saveValue->handle($dept, ['code' => 'ENG', 'name' => 'Engineering']);
    $saveValue->handle($dept, ['code' => 'OPS', 'name' => 'Operations']);

    // Invalid inputs throw.
    expect(fn () => $allocate->handle('', 100, [['percentage' => 100, 'dimensions' => ['department' => 'ENG']]]))
        ->toThrow(InvalidDimension::class, 'An allocation requires a key, positive amount, and at least one row.');

    expect(fn () => $allocate->handle('ALLOC-0', 100, []))
        ->toThrow(InvalidDimension::class, 'An allocation requires a key, positive amount, and at least one row.');

    expect(fn () => $allocate->handle('ALLOC-0', 0, [['percentage' => 100, 'dimensions' => ['department' => 'ENG']]]))
        ->toThrow(InvalidDimension::class, 'An allocation requires a key, positive amount, and at least one row.');

    expect(fn () => $allocate->handle('ALLOC-0', -50, [['percentage' => 100, 'dimensions' => ['department' => 'ENG']]]))
        ->toThrow(InvalidDimension::class, 'An allocation requires a key, positive amount, and at least one row.');

    expect(fn () => $allocate->handle('ALLOC-BAD-PCT', 100, [
        ['percentage' => 60, 'dimensions' => ['department' => 'ENG']],
        ['percentage' => 30, 'dimensions' => ['department' => 'OPS']],
    ]))->toThrow(InvalidDimension::class, 'Dimension allocation percentages must total 100.');

    // Validation runs before persisting.
    expect(fn () => $allocate->handle('ALLOC-BAD-DIM', 100, [
        ['percentage' => 100, 'dimensions' => ['department' => 'INVALID']],
    ]))->toThrow(InvalidDimension::class, 'Unknown or inactive department dimension value: INVALID.');

    // Valid allocation 1.
    $rows1 = $allocate->handle('INV-1001', 1000, [
        ['percentage' => 60, 'dimensions' => ['department' => 'ENG']],
        ['percentage' => 40, 'dimensions' => ['department' => 'OPS']],
    ], 'USD', 'user-1');

    expect($rows1)->toHaveCount(2)
        ->and($rows1[0])->toBeInstanceOf(DimensionAllocation::class)
        ->and((float) $rows1[0]->amount)->toBe(600.0)
        ->and((float) $rows1[0]->percentage)->toBe(60.0)
        ->and($rows1[0]->currency)->toBe('USD')
        ->and($rows1[0]->dimensions)->toBe(['department' => 'ENG'])
        ->and($rows1[0]->created_by)->toBe('user-1')
        ->and((float) $rows1[1]->amount)->toBe(400.0);

    // Duplicate allocation key throws.
    expect(fn () => $allocate->handle('INV-1001', 500, [
        ['percentage' => 100, 'dimensions' => ['department' => 'ENG']],
    ]))->toThrow(InvalidDimension::class, 'This allocation key has already been processed.');

    // Valid allocation 2 for aggregation.
    $allocate->handle('INV-1002', 500, [
        ['percentage' => 50, 'dimensions' => ['department' => 'ENG']],
        ['percentage' => 50, 'dimensions' => ['department' => 'OPS']],
    ], 'USD', 'user-2');

    $allBalances = $balances->handle();
    expect($allBalances)->toHaveCount(2);

    $byDept = collect($allBalances)->keyBy(static fn (array $row): string => $row['dimensions']['department']);
    expect((float) $byDept['ENG']['amount'])->toBe(850.0)
        ->and((float) $byDept['OPS']['amount'])->toBe(650.0);

    $filtered = $balances->handle('INV-1002');
    expect($filtered)->toHaveCount(2);
    $filteredByDept = collect($filtered)->keyBy(static fn (array $row): string => $row['dimensions']['department']);
    expect((float) $filteredByDept['ENG']['amount'])->toBe(250.0)
        ->and((float) $filteredByDept['OPS']['amount'])->toBe(250.0);
});
