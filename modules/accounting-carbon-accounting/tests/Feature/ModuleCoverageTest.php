<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Liberu\Accounting\CarbonAccounting\Actions\RecordCarbonActivity;
use Liberu\Accounting\CarbonAccounting\CarbonAccountingServiceProvider;
use Liberu\Accounting\CarbonAccounting\Models\CarbonActivity;
use Liberu\Accounting\CarbonAccounting\Queries\CarbonActivityQuery;

it('boots the carbon accounting service provider and schema', function (): void {
    $provider = app()->getProvider(CarbonAccountingServiceProvider::class);
    expect($provider)->toBeInstanceOf(CarbonAccountingServiceProvider::class);
    $provider->boot();

    expect(Schema::hasTable('accounting_carbon_activities'))->toBeTrue();
});

it('casts carbon activity model attributes properly', function (): void {
    $activity = CarbonActivity::query()->create([
        'team_id' => 1,
        'activity_date' => '2026-08-15',
        'scope' => 'scope_1',
        'category' => 'fleet_fuel',
        'description' => 'Diesel delivery vans',
        'quantity' => '100.500000',
        'unit' => 'litres',
        'emission_factor' => '2.68000000',
        'co2e' => '269.34000000',
        'factor_source' => 'DEFRA 2026',
        'evidence' => ['receipt_id' => 'RCP-1'],
        'is_estimate' => true,
        'metadata' => ['region' => 'UK'],
    ]);

    $fresh = $activity->fresh();

    expect($fresh->activity_date)->toBeInstanceOf(Carbon::class)
        ->and($fresh->activity_date->toDateString())->toBe('2026-08-15')
        ->and($fresh->quantity)->toBe('100.500000')
        ->and($fresh->emission_factor)->toBe('2.68000000')
        ->and($fresh->co2e)->toBe('269.34000000')
        ->and($fresh->evidence)->toBe(['receipt_id' => 'RCP-1'])
        ->and($fresh->is_estimate)->toBeTrue()
        ->and($fresh->metadata)->toBe(['region' => 'UK']);
});

it('records a carbon activity and calculates co2e', function (): void {
    $action = new RecordCarbonActivity();

    $activity = $action->handle([
        'team_id' => 7,
        'activity_date' => '2026-09-01',
        'scope' => 'scope_2',
        'category' => 'electricity',
        'description' => 'HQ office electricity',
        'quantity' => 1250,
        'unit' => 'kWh',
        'emission_factor' => 0.21,
        'factor_source' => 'BEIS',
        'evidence' => ['meter' => 'M-100'],
        'is_estimate' => false,
        'metadata' => ['site' => 'London'],
    ]);

    expect($activity->exists)->toBeTrue()
        ->and((float) $activity->co2e)->toEqualWithDelta(262.5, 0.0001)
        ->and($activity->factor_source)->toBe('BEIS')
        ->and($activity->is_estimate)->toBeFalse();
});

it('validates required fields when recording a carbon activity', function (string $missingField): void {
    $attributes = [
        'team_id' => 1,
        'activity_date' => '2026-09-01',
        'scope' => 'scope_1',
        'category' => 'gas',
        'description' => 'Heating',
        'quantity' => 10,
        'unit' => 'm3',
        'emission_factor' => 1.5,
    ];

    unset($attributes[$missingField]);

    expect(fn () => (new RecordCarbonActivity())->handle($attributes))
        ->toThrow(InvalidArgumentException::class, "{$missingField} is required.");
})->with([
    'team_id',
    'activity_date',
    'scope',
    'category',
    'quantity',
    'unit',
    'emission_factor',
]);

it('queries carbon activities with scope filtering and perPage clamping', function (): void {
    $query = new CarbonActivityQuery();

    expect($query->paginate(99)->total())->toBe(0);

    $action = new RecordCarbonActivity();
    $action->handle([
        'team_id' => 10,
        'activity_date' => '2026-01-10',
        'scope' => 'scope_1',
        'category' => 'fuel',
        'description' => 'January fuel',
        'quantity' => 10,
        'unit' => 'L',
        'emission_factor' => 2.5,
    ]);
    $action->handle([
        'team_id' => 10,
        'activity_date' => '2026-03-10',
        'scope' => 'scope_2',
        'category' => 'power',
        'description' => 'March power',
        'quantity' => 50,
        'unit' => 'kWh',
        'emission_factor' => 0.2,
    ]);
    $action->handle([
        'team_id' => 11,
        'activity_date' => '2026-04-10',
        'scope' => 'scope_1',
        'category' => 'fuel',
        'description' => 'Other team',
        'quantity' => 5,
        'unit' => 'L',
        'emission_factor' => 2.5,
    ]);

    $allForTeam = $query->paginate(10);
    expect($allForTeam->total())->toBe(2)
        ->and($allForTeam->items()[0]->description)->toBe('March power')
        ->and($allForTeam->items()[1]->description)->toBe('January fuel');

    $scope1Only = $query->paginate(10, 'scope_1');
    expect($scope1Only->total())->toBe(1)
        ->and($scope1Only->items()[0]->scope)->toBe('scope_1');

    $minClamped = $query->paginate(10, null, 0);
    expect($minClamped->perPage())->toBe(1);

    $maxClamped = $query->paginate(10, null, 500);
    expect($maxClamped->perPage())->toBe(100);
});
