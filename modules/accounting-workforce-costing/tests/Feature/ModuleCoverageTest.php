<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Liberu\Accounting\WorkforceCosting\Actions\AllocateWorkforceCost;
use Liberu\Accounting\WorkforceCosting\Actions\CapitalizeWorkforceCost;
use Liberu\Accounting\WorkforceCosting\Actions\CreateWorkforceCostingRule;
use Liberu\Accounting\WorkforceCosting\Actions\RecordWorkforceCost;
use Liberu\Accounting\WorkforceCosting\Enums\WorkforceAllocationType;
use Liberu\Accounting\WorkforceCosting\Enums\WorkforceCostStatus;
use Liberu\Accounting\WorkforceCosting\Exceptions\InvalidWorkforceCost;
use Liberu\Accounting\WorkforceCosting\Models\WorkforceCost;
use Liberu\Accounting\WorkforceCosting\Models\WorkforceCostingRule;
use Liberu\Accounting\WorkforceCosting\Queries\WorkforceProfitability;
use Liberu\Accounting\WorkforceCosting\WorkforceCostingServiceProvider;

it('registers workforce costing service provider and creates tables', function (): void {
    expect(app()->getProvider(WorkforceCostingServiceProvider::class))
        ->toBeInstanceOf(WorkforceCostingServiceProvider::class);
    expect(Schema::hasTable('accounting_workforce_costs'))->toBeTrue();
    expect(Schema::hasTable('accounting_workforce_costing_rules'))->toBeTrue();
});

it('exposes workforce costing enums', function (): void {
    expect(array_map(static fn (WorkforceAllocationType $c): string => $c->value, WorkforceAllocationType::cases()))
        ->toBe(['project', 'department', 'class', 'location']);
    expect(array_map(static fn (WorkforceCostStatus $c): string => $c->value, WorkforceCostStatus::cases()))
        ->toBe(['draft', 'allocated', 'posted']);
});

it('creates workforce costing rules with enum or string allocation types and validates inputs', function (): void {
    $createRule = app(CreateWorkforceCostingRule::class);

    $rule1 = $createRule->handle([
        'team_id' => 1,
        'name' => 'R&D Capitalization',
        'allocation_type' => WorkforceAllocationType::Project,
        'allocation_ref' => 'PROJ-RD',
        'hourly_rate' => 95.5,
        'capitalize' => true,
        'active' => true,
        'metadata' => ['gl' => '1500'],
    ]);

    expect($rule1)->toBeInstanceOf(WorkforceCostingRule::class)
        ->and($rule1->allocation_type)->toBe(WorkforceAllocationType::Project)
        ->and($rule1->hourly_rate)->toBe('95.500000')
        ->and($rule1->capitalize)->toBeTrue()
        ->and($rule1->active)->toBeTrue()
        ->and($rule1->metadata)->toBe(['gl' => '1500']);

    $rule2 = $createRule->handle([
        'team_id' => 1,
        'name' => 'Ops Overhead',
        'allocation_type' => 'department',
    ]);
    expect($rule2->allocation_type)->toBe(WorkforceAllocationType::Department);

    foreach ([
        ['name' => 'No Team', 'allocation_type' => 'project'],
        ['team_id' => 1, 'allocation_type' => 'project'],
        ['team_id' => 1, 'name' => 'Bad Type', 'allocation_type' => 'invalid'],
    ] as $invalid) {
        expect(fn () => $createRule->handle($invalid))
            ->toThrow(InvalidWorkforceCost::class, 'A team, rule name, and valid allocation type are required.');
    }
});

it('records workforce costs, computes amount, and validates required and non-negative fields', function (): void {
    $record = app(RecordWorkforceCost::class);

    $computed = $record->handle([
        'team_id' => 2,
        'worker_ref' => 'emp-10',
        'source_type' => 'TimeEntry',
        'source_id' => 'TE-100',
        'cost_date' => '2026-03-10',
        'hours' => 8,
        'hourly_rate' => 50,
        'metadata' => ['role' => 'engineer'],
    ]);

    expect($computed)->toBeInstanceOf(WorkforceCost::class)
        ->and($computed->status)->toBe(WorkforceCostStatus::Draft)
        ->and($computed->cost_date->toDateString())->toBe('2026-03-10')
        ->and($computed->hours)->toBe('8.000000')
        ->and($computed->hourly_rate)->toBe('50.000000')
        ->and($computed->amount)->toBe('400.000000')
        ->and($computed->capitalized)->toBeFalse()
        ->and($computed->metadata)->toBe(['role' => 'engineer']);

    $explicit = $record->handle([
        'team_id' => 2,
        'worker_ref' => 'emp-11',
        'cost_date' => '2026-03-11',
        'hours' => 4,
        'hourly_rate' => 50,
        'amount' => 250,
    ]);
    expect($explicit->amount)->toBe('250.000000');

    foreach ([
        ['worker_ref' => 'emp-10', 'cost_date' => '2026-03-10'],
        ['team_id' => 2, 'cost_date' => '2026-03-10'],
        ['team_id' => 2, 'worker_ref' => 'emp-10'],
    ] as $missing) {
        expect(fn () => $record->handle($missing))
            ->toThrow(InvalidWorkforceCost::class, 'A team, worker reference, and cost date are required.');
    }

    foreach ([
        ['hours' => -1],
        ['hourly_rate' => -1],
        ['amount' => -1],
    ] as $negative) {
        expect(fn () => $record->handle(array_merge([
            'team_id' => 2,
            'worker_ref' => 'emp-10',
            'cost_date' => '2026-03-10',
        ], $negative)))->toThrow(InvalidWorkforceCost::class, 'Workforce hours, rates, and amounts cannot be negative.');
    }
});

it('allocates and capitalizes workforce costs and enforces preconditions', function (): void {
    $record = app(RecordWorkforceCost::class);
    $allocate = app(AllocateWorkforceCost::class);
    $capitalize = app(CapitalizeWorkforceCost::class);

    $cost = $record->handle([
        'team_id' => 3,
        'worker_ref' => 'emp-20',
        'cost_date' => '2026-04-01',
        'hours' => 5,
        'hourly_rate' => 100,
    ]);

    expect(fn () => $capitalize->handle($cost))
        ->toThrow(InvalidWorkforceCost::class, 'A workforce cost must be allocated before capitalization.');

    expect(fn () => $allocate->handle($cost, 'bad-type', 'REF-1'))
        ->toThrow(InvalidWorkforceCost::class, 'A valid allocation type and reference are required.');
    expect(fn () => $allocate->handle($cost, WorkforceAllocationType::Project, ''))
        ->toThrow(InvalidWorkforceCost::class, 'A valid allocation type and reference are required.');

    $allocated = $allocate->handle($cost, WorkforceAllocationType::Project, 'PROJ-42');
    expect($allocated->allocation_type)->toBe(WorkforceAllocationType::Project)
        ->and($allocated->allocation_ref)->toBe('PROJ-42')
        ->and($allocated->status)->toBe(WorkforceCostStatus::Allocated);

    $allocatedByString = $allocate->handle($allocated, 'location', 'LOC-NYC');
    expect($allocatedByString->allocation_type)->toBe(WorkforceAllocationType::Location)
        ->and($allocatedByString->allocation_ref)->toBe('LOC-NYC');

    $posted = $capitalize->handle($allocatedByString);
    expect($posted->capitalized)->toBeTrue()
        ->and($posted->status)->toBe(WorkforceCostStatus::Posted);
});

it('aggregates workforce profitability by allocation dimension and date range', function (): void {
    $record = app(RecordWorkforceCost::class);
    $allocate = app(AllocateWorkforceCost::class);
    $capitalize = app(CapitalizeWorkforceCost::class);
    $query = app(WorkforceProfitability::class);

    expect($query->handle(99))->toBe([]);

    // Unallocated draft cost should be ignored by profitability query.
    $record->handle([
        'team_id' => 4,
        'worker_ref' => 'emp-unalloc',
        'cost_date' => '2026-05-05',
        'hours' => 3,
        'hourly_rate' => 100,
    ]);

    $c1 = $allocate->handle($record->handle([
        'team_id' => 4,
        'worker_ref' => 'emp-1',
        'cost_date' => '2026-05-01',
        'hours' => 4,
        'hourly_rate' => 100,
    ]), WorkforceAllocationType::Project, 'ALPHA');
    $capitalize->handle($c1);

    $allocate->handle($record->handle([
        'team_id' => 4,
        'worker_ref' => 'emp-2',
        'cost_date' => '2026-05-10',
        'hours' => 6,
        'hourly_rate' => 50,
    ]), WorkforceAllocationType::Project, 'ALPHA');

    // Cost with allocation_type set and null allocation_ref exercises 'unassigned' fallback.
    WorkforceCost::create([
        'team_id' => 4,
        'worker_ref' => 'emp-3',
        'cost_date' => '2026-05-20',
        'hours' => 2,
        'hourly_rate' => 75,
        'amount' => 150,
        'allocation_type' => WorkforceAllocationType::ClassDimension,
        'allocation_ref' => null,
        'capitalized' => false,
        'status' => WorkforceCostStatus::Allocated,
    ]);

    expect($query->handle(4))->toBe([
        'project:ALPHA' => [
            'amount' => '700',
            'hours' => '10',
            'capitalized' => '400',
        ],
        'class:unassigned' => [
            'amount' => '150',
            'hours' => '2',
            'capitalized' => '0',
        ],
    ]);

    expect($query->handle(4, '2026-05-05', '2026-05-15'))->toBe([
        'project:ALPHA' => [
            'amount' => '300',
            'hours' => '6',
            'capitalized' => '0',
        ],
    ]);
});
