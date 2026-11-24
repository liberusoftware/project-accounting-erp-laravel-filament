<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Liberu\Accounting\AccountantWorkspace\AccountantWorkspaceServiceProvider;
use Liberu\Accounting\AccountantWorkspace\Actions\CreateWorkspaceItem;
use Liberu\Accounting\AccountantWorkspace\Enums\WorkspaceItemStatus;
use Liberu\Accounting\AccountantWorkspace\Models\WorkspaceItem;
use Liberu\Accounting\AccountantWorkspace\Queries\WorkspaceQuery;

it('boots the service provider and covers enum, model, create action, and workspace query', function (): void {
    $provider = $this->app->register(AccountantWorkspaceServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(AccountantWorkspaceServiceProvider::class);

    expect(array_map(fn (WorkspaceItemStatus $s): string => $s->value, WorkspaceItemStatus::cases()))
        ->toBe(['active', 'at_risk', 'waiting', 'complete']);

    $create = app(CreateWorkspaceItem::class);
    $query = app(WorkspaceQuery::class);

    // Empty query
    expect($query->paginate(77)->total())->toBe(0);

    // Validation branches
    expect(fn () => $create->handle(['name' => 'Month End']))
        ->toThrow(InvalidArgumentException::class, 'team_id is required.');
    expect(fn () => $create->handle(['team_id' => 1]))
        ->toThrow(InvalidArgumentException::class, 'name is required.');
    expect(fn () => $create->handle(['team_id' => 1, 'name' => '   ']))
        ->toThrow(InvalidArgumentException::class, 'name is required.');

    // Happy path with defaults and casts
    $noDeadline = $create->handle([
        'team_id' => 1,
        'subject_type' => 'CloseChecklist',
        'subject_id' => '10',
        'name' => 'No Deadline Item',
        'alerts' => [['level' => 'info']],
        'notes' => [['body' => 'Check trial balance']],
        'requests' => [['id' => 1]],
        'metadata' => ['source' => 'close'],
    ]);

    expect($noDeadline)->toBeInstanceOf(WorkspaceItem::class)
        ->and($noDeadline->status)->toBe(WorkspaceItemStatus::Active)
        ->and($noDeadline->alerts)->toBe([['level' => 'info']])
        ->and($noDeadline->notes)->toBe([['body' => 'Check trial balance']])
        ->and($noDeadline->requests)->toBe([['id' => 1]])
        ->and($noDeadline->metadata)->toBe(['source' => 'close']);

    $later = $create->handle([
        'team_id' => 1,
        'name' => 'VAT Filing',
        'status' => WorkspaceItemStatus::AtRisk,
        'next_deadline' => '2026-05-15 12:00:00',
    ]);

    $earlier = $create->handle([
        'team_id' => 1,
        'name' => 'Bank Rec',
        'status' => WorkspaceItemStatus::Waiting,
        'next_deadline' => '2026-05-01 09:00:00',
    ]);

    $create->handle([
        'team_id' => 2,
        'name' => 'Other Team Item',
        'status' => WorkspaceItemStatus::Complete,
    ]);

    expect($earlier->next_deadline)->toBeInstanceOf(CarbonImmutable::class);

    // Ordering: non-null deadlines ascending, nulls last
    $page = $query->paginate(1);
    expect($page->total())->toBe(3)
        ->and(array_map(fn (WorkspaceItem $i): string => $i->name, $page->items()))
        ->toBe(['Bank Rec', 'VAT Filing', 'No Deadline Item']);

    // Status filter and perPage clamping
    $filtered = $query->paginate(1, WorkspaceItemStatus::AtRisk->value);
    expect($filtered->total())->toBe(1)
        ->and($filtered->items()[0]->id)->toBe($later->id);

    expect($query->paginate(1, null, 0)->perPage())->toBe(1)
        ->and($query->paginate(1, null, 500)->perPage())->toBe(100);
});
