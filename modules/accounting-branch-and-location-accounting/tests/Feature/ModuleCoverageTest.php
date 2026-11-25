<?php

declare(strict_types=1);

use Liberu\Accounting\BranchLocationAccounting\Actions\AllocateBranchValue;
use Liberu\Accounting\BranchLocationAccounting\Actions\CreateBranch;
use Liberu\Accounting\BranchLocationAccounting\BranchLocationAccountingServiceProvider;
use Liberu\Accounting\BranchLocationAccounting\Models\Branch;
use Liberu\Accounting\BranchLocationAccounting\Queries\BranchQuery;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('boots branch and location accounting service provider', function (): void {
    $provider = $this->app->register(BranchLocationAccountingServiceProvider::class, force: true);
    $provider->boot();

    expect($provider)->toBeInstanceOf(BranchLocationAccountingServiceProvider::class);
});

it('creates, queries, allocates values, and validates branches', function (): void {
    $query = app(BranchQuery::class);
    expect($query->forTeam(10))->toHaveCount(0);

    $create = app(CreateBranch::class);
    $branchB = $create->handle([
        'team_id' => 10,
        'code' => 'BR-002',
        'name' => 'Manchester Office',
        'location' => 'Manchester, UK',
        'local_tax_code' => 'UK-MAN',
        'sequence_prefix' => 'MAN-',
        'allocation_rule' => 'headcount',
        'performance_target' => 250000.12345678,
        'statutory_reference' => 'REG-MAN',
        'metadata' => ['region' => 'North'],
    ]);

    $branchA = $create->handle([
        'team_id' => 10,
        'code' => 'BR-001',
        'name' => 'London HQ',
        'status' => 'inactive',
    ]);

    $otherTeam = $create->handle([
        'team_id' => 20,
        'code' => 'BR-999',
        'name' => 'Dublin Branch',
    ]);

    expect($branchB->status)->toBe('active')
        ->and($branchB->metadata)->toBe(['region' => 'North'])
        ->and((float) $branchB->performance_target)->toBeFloat();

    expect($query->forTeam(10)->pluck('code')->all())->toBe(['BR-001', 'BR-002'])
        ->and($query->forTeam(10, 'active')->pluck('id')->all())->toBe([$branchB->getKey()])
        ->and($query->forTeam(10, 'inactive')->pluck('id')->all())->toBe([$branchA->getKey()])
        ->and($query->forTeam(20)->pluck('id')->all())->toBe([$otherTeam->getKey()]);

    $allocate = app(AllocateBranchValue::class);
    expect($allocate->handle($branchB, 1500.50))->toBe([
        'branch_id' => $branchB->getKey(),
        'amount' => 1500.50,
        'rule' => 'headcount',
        'status' => 'allocated',
    ])
        ->and($allocate->handle($branchA, 0.0))->toBe([
            'branch_id' => $branchA->getKey(),
            'amount' => 0.0,
            'rule' => null,
            'status' => 'allocated',
        ]);

    expect(fn () => $create->handle(['code' => 'X', 'name' => 'Y']))->toThrow(HttpException::class)
        ->and(fn () => $create->handle(['team_id' => 1, 'name' => 'Y']))->toThrow(HttpException::class)
        ->and(fn () => $create->handle(['team_id' => 1, 'code' => 'X']))->toThrow(HttpException::class)
        ->and(fn () => $allocate->handle($branchB, -0.01))->toThrow(HttpException::class);
});
