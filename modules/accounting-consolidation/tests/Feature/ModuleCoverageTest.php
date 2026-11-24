<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Liberu\Accounting\Consolidation\Actions\AddConsolidationEntity;
use Liberu\Accounting\Consolidation\Actions\CreateConsolidationGroup;
use Liberu\Accounting\Consolidation\Actions\PrepareConsolidatedReport;
use Liberu\Accounting\Consolidation\Actions\PublishConsolidatedReport;
use Liberu\Accounting\Consolidation\Actions\RecordEliminations;
use Liberu\Accounting\Consolidation\ConsolidationServiceProvider;
use Liberu\Accounting\Consolidation\Enums\ConsolidationStatus;
use Liberu\Accounting\Consolidation\Exceptions\InvalidConsolidation;
use Liberu\Accounting\Consolidation\Models\ConsolidationGroup;
use Liberu\Accounting\Consolidation\Queries\ConsolidationQuery;

it('registers the consolidation service provider and runs migrations', function (): void {
    expect(app()->getProvider(ConsolidationServiceProvider::class))
        ->toBeInstanceOf(ConsolidationServiceProvider::class);

    expect(Schema::hasTable('accounting_consolidation_groups'))->toBeTrue();
    expect(Schema::hasColumns('accounting_consolidation_groups', [
        'id',
        'team_id',
        'group_ref',
        'name',
        'reporting_currency',
        'status',
        'entities',
        'eliminations',
        'translation',
        'report',
    ]))->toBeTrue();
});

it('exposes all consolidation status enum cases', function (): void {
    expect(array_column(ConsolidationStatus::cases(), 'value'))
        ->toBe(['draft', 'ready', 'reported']);
});

it('creates consolidation groups and validates required attributes and currency code', function (): void {
    $create = new CreateConsolidationGroup();

    $group = $create->handle([
        'team_id' => 21,
        'group_ref' => 'GRP-EU',
        'name' => 'European Holdings',
        'reporting_currency' => 'EUR',
    ]);

    expect($group)->toBeInstanceOf(ConsolidationGroup::class)
        ->and($group->status)->toBe(ConsolidationStatus::Draft)
        ->and($group->reporting_currency)->toBe('EUR');

    foreach (['team_id', 'group_ref', 'name', 'reporting_currency'] as $missingField) {
        $payload = [
            'team_id' => 21,
            'group_ref' => 'GRP-ERR',
            'name' => 'Error Group',
            'reporting_currency' => 'USD',
        ];
        unset($payload[$missingField]);

        expect(fn () => $create->handle($payload))
            ->toThrow(InvalidConsolidation::class, "{$missingField} is required.");
    }

    foreach (['eur', 'US', 'USDX', '12A'] as $badCurrency) {
        expect(fn () => $create->handle([
            'team_id' => 21,
            'group_ref' => 'GRP-CUR',
            'name' => 'Bad Currency',
            'reporting_currency' => $badCurrency,
        ]))->toThrow(InvalidConsolidation::class, 'Reporting currency must be a three-letter uppercase code.');
    }
});

it('manages entities, eliminations, report preparation, and publishing', function (): void {
    $create = new CreateConsolidationGroup();
    $addEntity = new AddConsolidationEntity();
    $recordElimination = new RecordEliminations();
    $prepare = new PrepareConsolidatedReport();
    $publish = new PublishConsolidatedReport();

    $group = $create->handle([
        'team_id' => 22,
        'group_ref' => 'GRP-GLOBAL',
        'name' => 'Global Corp',
        'reporting_currency' => 'USD',
    ]);

    expect(fn () => $prepare->handle($group, ['period' => '2026-Q3']))
        ->toThrow(InvalidConsolidation::class, 'At least one entity and a reporting period are required.');

    expect(fn () => $publish->handle($group))
        ->toThrow(InvalidConsolidation::class, 'Only ready consolidated reports can be published.');

    expect(fn () => $addEntity->handle($group, ['ownership_percent' => 100]))
        ->toThrow(InvalidConsolidation::class, 'Entity reference and ownership percentage are required.');
    expect(fn () => $addEntity->handle($group, ['entity_ref' => 'SUB-1']))
        ->toThrow(InvalidConsolidation::class, 'Entity reference and ownership percentage are required.');
    expect(fn () => $addEntity->handle($group, ['entity_ref' => 'SUB-1', 'ownership_percent' => 0]))
        ->toThrow(InvalidConsolidation::class, 'Ownership percentage must be greater than zero and no more than 100.');
    expect(fn () => $addEntity->handle($group, ['entity_ref' => 'SUB-1', 'ownership_percent' => 100.5]))
        ->toThrow(InvalidConsolidation::class, 'Ownership percentage must be greater than zero and no more than 100.');

    $group = $addEntity->handle($group, ['entity_ref' => 'SUB-UK', 'ownership_percent' => 100]);
    $group = $addEntity->handle($group, ['entity_ref' => 'SUB-DE', 'ownership_percent' => 75.5]);
    expect($group->entities)->toHaveCount(2);

    expect(fn () => $recordElimination->handle($group, ['amount' => 500]))
        ->toThrow(InvalidConsolidation::class, 'Elimination reference and amount are required.');
    expect(fn () => $recordElimination->handle($group, ['reference' => 'ELIM-1']))
        ->toThrow(InvalidConsolidation::class, 'Elimination reference and amount are required.');

    $group = $recordElimination->handle($group, ['reference' => 'ELIM-IC-1', 'amount' => 12000]);
    $group = $recordElimination->handle($group, ['reference' => 'ELIM-IC-2', 'amount' => 0]);
    expect($group->eliminations)->toHaveCount(2);

    expect(fn () => $prepare->handle($group, []))
        ->toThrow(InvalidConsolidation::class, 'At least one entity and a reporting period are required.');

    $group = $prepare->handle($group, ['period' => '2026-Q3', 'net_income' => 450000]);
    expect($group->status)->toBe(ConsolidationStatus::Ready)
        ->and($group->report)->toBe(['period' => '2026-Q3', 'net_income' => 450000]);

    $group = $publish->handle($group, ['fx_method' => 'closing_rate', 'cta' => 1250.25]);
    expect($group->status)->toBe(ConsolidationStatus::Reported)
        ->and($group->translation)->toBe(['fx_method' => 'closing_rate', 'cta' => 1250.25]);

    expect(fn () => $publish->handle($group))
        ->toThrow(InvalidConsolidation::class, 'Only ready consolidated reports can be published.');
});

it('queries consolidation groups by team ordered by latest id', function (): void {
    $create = new CreateConsolidationGroup();
    $query = new ConsolidationQuery();

    expect($query->forTeam(888))->toBeEmpty();

    $first = $create->handle([
        'team_id' => 25,
        'group_ref' => 'GRP-1',
        'name' => 'Group 1',
        'reporting_currency' => 'USD',
    ]);

    $second = $create->handle([
        'team_id' => 25,
        'group_ref' => 'GRP-2',
        'name' => 'Group 2',
        'reporting_currency' => 'GBP',
    ]);

    $create->handle([
        'team_id' => 26,
        'group_ref' => 'GRP-3',
        'name' => 'Group 3',
        'reporting_currency' => 'EUR',
    ]);

    $results = $query->forTeam(25);

    expect($results)->toHaveCount(2)
        ->and($results->pluck('id')->all())->toBe([$second->id, $first->id]);
});
