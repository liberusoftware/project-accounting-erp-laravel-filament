<?php

declare(strict_types=1);

use Liberu\Accounting\Budgets\Actions\AddBudgetLine;
use Liberu\Accounting\Budgets\Actions\ApproveBudget;
use Liberu\Accounting\Budgets\Actions\CreateBudget;
use Liberu\Accounting\Budgets\Actions\ReviseBudget;
use Liberu\Accounting\Budgets\Actions\SubmitBudget;
use Liberu\Accounting\Budgets\BudgetsServiceProvider;
use Liberu\Accounting\Budgets\Enums\BudgetStatus;
use Liberu\Accounting\Budgets\Exceptions\InvalidBudget;
use Liberu\Accounting\Budgets\Models\Budget;
use Liberu\Accounting\Budgets\Models\BudgetLine;
use Liberu\Accounting\Budgets\Queries\BudgetQuery;

it('boots budgets service provider and covers enum and exception', function (): void {
    $provider = $this->app->register(BudgetsServiceProvider::class, force: true);
    $provider->boot();

    expect($provider)->toBeInstanceOf(BudgetsServiceProvider::class)
        ->and(array_column(BudgetStatus::cases(), 'value'))->toBe(['draft', 'submitted', 'approved', 'rejected', 'revised'])
        ->and((new InvalidBudget('Budget error'))->getMessage())->toBe('Budget error');
});

it('executes budget creation, line additions, submission, approval, revision, and queries', function (): void {
    $query = app(BudgetQuery::class);
    expect($query->paginate(5)->total())->toBe(0);

    $budget = app(CreateBudget::class)->handle([
        'team_id' => 5,
        'name' => 'FY2026 Operating Budget',
        'period_start' => '2026-01-01',
        'period_end' => '2026-12-31',
        'currency' => 'eur',
        'notes' => 'Initial draft',
        'metadata' => ['department' => 'engineering'],
    ]);

    expect($budget->status)->toBe(BudgetStatus::Draft)
        ->and($budget->currency)->toBe('EUR')
        ->and($budget->version)->toBe(1)
        ->and($budget->period_start->toDateString())->toBe('2026-01-01')
        ->and($budget->period_end->toDateString())->toBe('2026-12-31')
        ->and($budget->metadata)->toBe(['department' => 'engineering']);

    $line = app(AddBudgetLine::class)->handle($budget, [
        'account_id' => 4001,
        'project_id' => 12,
        'dimensions' => ['cost_center' => 'CC-1'],
        'planned_amount' => 120000.50,
        'phases' => ['Q1' => 30000, 'Q2' => 30000],
        'notes' => 'Cloud infra',
    ]);

    expect($line)->toBeInstanceOf(BudgetLine::class)
        ->and((float) $line->planned_amount)->toBe(120000.50)
        ->and($line->dimensions)->toBe(['cost_center' => 'CC-1'])
        ->and($line->phases)->toBe(['Q1' => 30000, 'Q2' => 30000])
        ->and($line->budget->is($budget))->toBeTrue()
        ->and($budget->lines()->count())->toBe(1);

    $submitted = app(SubmitBudget::class)->handle($budget, 11);
    expect($submitted->status)->toBe(BudgetStatus::Submitted)
        ->and($submitted->submitted_by)->toBe(11);

    $approved = app(ApproveBudget::class)->handle($submitted, 22);
    expect($approved->status)->toBe(BudgetStatus::Approved)
        ->and($approved->approved_by)->toBe(22)
        ->and($approved->approved_at)->not->toBeNull();

    $revised = app(ReviseBudget::class)->handle($approved, ['notes' => 'Mid-year revision']);
    expect($revised->status)->toBe(BudgetStatus::Revised)
        ->and($revised->version)->toBe(2)
        ->and($revised->notes)->toBe('Mid-year revision');

    // Adding a line to a revised budget and resubmitting works
    $extraLine = app(AddBudgetLine::class)->handle($revised, [
        'account_id' => 4002,
        'planned_amount' => 15000,
    ]);
    expect($extraLine->project_id)->toBeNull();

    $resubmitted = app(SubmitBudget::class)->handle($revised, 11);
    expect($resubmitted->status)->toBe(BudgetStatus::Submitted);

    $page = $query->paginate(5, 'submitted', 200);
    expect($page->total())->toBe(1)
        ->and($page->perPage())->toBe(100)
        ->and($page->items()[0]->lines)->toHaveCount(2);

    $emptyStatusPage = $query->paginate(5, 'draft', 0);
    expect($emptyStatusPage->total())->toBe(0)
        ->and($emptyStatusPage->perPage())->toBe(1);
});

it('enforces validation and state transitions across budget actions', function (): void {
    $create = app(CreateBudget::class);
    $valid = [
        'team_id' => 9,
        'name' => 'Q1 Budget',
        'period_start' => '2026-01-01',
        'period_end' => '2026-03-31',
        'currency' => 'USD',
    ];

    foreach (['team_id', 'name', 'period_start', 'period_end', 'currency'] as $required) {
        expect(fn () => $create->handle(array_diff_key($valid, [$required => true])))
            ->toThrow(InvalidBudget::class, "{$required} is required.");
    }

    expect(fn () => $create->handle([...$valid, 'period_end' => '2025-12-31']))
        ->toThrow(InvalidBudget::class, 'period is invalid');

    $draft = $create->handle($valid);

    $addLine = app(AddBudgetLine::class);
    expect(fn () => $addLine->handle($draft, ['planned_amount' => 100]))->toThrow(InvalidBudget::class, 'account_id is required.')
        ->and(fn () => $addLine->handle($draft, ['account_id' => 1]))->toThrow(InvalidBudget::class, 'planned_amount is required.');

    // Cannot submit without lines, cannot approve draft, cannot revise draft
    expect(fn () => app(SubmitBudget::class)->handle($draft, 1))->toThrow(InvalidBudget::class, 'with lines')
        ->and(fn () => app(ApproveBudget::class)->handle($draft, 1))->toThrow(InvalidBudget::class, 'Only submitted')
        ->and(fn () => app(ReviseBudget::class)->handle($draft, []))->toThrow(InvalidBudget::class, 'Only approved');

    $addLine->handle($draft, ['account_id' => 1, 'planned_amount' => 500]);
    $submitted = app(SubmitBudget::class)->handle($draft, 1);

    expect(fn () => $addLine->handle($submitted, ['account_id' => 2, 'planned_amount' => 100]))
        ->toThrow(InvalidBudget::class, 'draft or revised')
        ->and(fn () => app(SubmitBudget::class)->handle($submitted, 1))
        ->toThrow(InvalidBudget::class, 'draft or revised');
});
