<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Liberu\Accounting\EmployeeExpenses\Actions\AddExpenseItem;
use Liberu\Accounting\EmployeeExpenses\Actions\CreateExpenseClaim;
use Liberu\Accounting\EmployeeExpenses\Actions\DecideExpenseClaim;
use Liberu\Accounting\EmployeeExpenses\Actions\PostExpenseClaim;
use Liberu\Accounting\EmployeeExpenses\Actions\ReimburseExpenseClaim;
use Liberu\Accounting\EmployeeExpenses\Actions\SubmitExpenseClaim;
use Liberu\Accounting\EmployeeExpenses\EmployeeExpensesServiceProvider;
use Liberu\Accounting\EmployeeExpenses\Enums\ClaimStatus;
use Liberu\Accounting\EmployeeExpenses\Events\ClaimLifecycleChanged;
use Liberu\Accounting\EmployeeExpenses\Exceptions\InvalidClaim;
use Liberu\Accounting\EmployeeExpenses\Models\ExpenseCategory;
use Liberu\Accounting\EmployeeExpenses\Models\ExpenseClaim;
use Liberu\Accounting\EmployeeExpenses\Models\ExpenseHistory;
use Liberu\Accounting\EmployeeExpenses\Models\ExpenseItem;
use Liberu\Accounting\EmployeeExpenses\Queries\ExpenseClaimQuery;

it('boots employee expenses service provider and covers enum, category model, and exception', function (): void {
    $provider = $this->app->register(EmployeeExpensesServiceProvider::class, force: true);
    $provider->boot();

    $category = ExpenseCategory::create([
        'team_id' => 3,
        'category_ref' => 'TRAVEL',
        'name' => 'Business Travel',
        'daily_limit' => 250.00,
        'requires_receipt' => true,
        'metadata' => ['gl_account' => '6100'],
    ]);

    expect($provider)->toBeInstanceOf(EmployeeExpensesServiceProvider::class)
        ->and(array_column(ClaimStatus::cases(), 'value'))->toBe([
            'draft',
            'submitted',
            'approved',
            'rejected',
            'reimbursed',
            'posted',
        ])
        ->and((new InvalidClaim('Claim error'))->getMessage())->toBe('Claim error')
        ->and($category->name)->toBe('Business Travel')
        ->and((float) $category->daily_limit)->toBe(250.00)
        ->and($category->requires_receipt)->toBeTrue()
        ->and($category->metadata)->toBe(['gl_account' => '6100']);
});

it('executes full employee expense claim lifecycle, rejection flow, totals, and queries', function (): void {
    Event::fake([ClaimLifecycleChanged::class]);

    $query = app(ExpenseClaimQuery::class);
    expect($query->paginate()->total())->toBe(0);

    $claim = app(CreateExpenseClaim::class)->handle([
        'team_id' => 7,
        'employee_ref' => 'EMP-001',
        'claim_ref' => 'CLM-2026-001',
        'currency' => 'gbp',
        'project_ref' => 'PRJ-9',
        'metadata' => ['trip' => 'London'],
        'actor_ref' => 'emp:1',
    ]);

    expect($claim)->toBeInstanceOf(ExpenseClaim::class)
        ->and($claim->status)->toBe(ClaimStatus::Draft)
        ->and($claim->currency)->toBe('GBP')
        ->and($claim->metadata)->toBe(['trip' => 'London'])
        ->and($claim->history()->count())->toBe(1);

    $createdHistory = $claim->history()->first();
    expect($createdHistory)->toBeInstanceOf(ExpenseHistory::class)
        ->and($createdHistory->event)->toBe('created')
        ->and($createdHistory->claim->is($claim))->toBeTrue();

    $item1 = app(AddExpenseItem::class)->handle($claim, [
        'category_ref' => 'TRAVEL',
        'spent_on' => '2026-03-10',
        'description' => 'Train ticket',
        'amount' => 120.50,
        'tax_amount' => 20.08,
        'merchant' => 'National Rail',
        'receipt_ref' => 'REC-1',
        'per_diem_days' => 1.5,
        'attendees' => ['Alice', 'Bob'],
        'metadata' => ['class' => 'standard'],
    ]);

    $item2 = app(AddExpenseItem::class)->handle($claim, [
        'category_ref' => 'MEALS',
        'spent_on' => '2026-03-11',
        'description' => 'Client dinner',
        'amount' => 79.50,
    ]);

    expect($item1)->toBeInstanceOf(ExpenseItem::class)
        ->and($item1->claim->is($claim))->toBeTrue()
        ->and((float) $item1->amount)->toBe(120.50)
        ->and((float) $item1->tax_amount)->toBe(20.08)
        ->and((float) $item1->per_diem_days)->toBe(1.5)
        ->and($item1->attendees)->toBe(['Alice', 'Bob'])
        ->and($item1->metadata)->toBe(['class' => 'standard'])
        ->and($item2->claim->is($claim))->toBeTrue()
        ->and($query->total($claim))->toBe(200.0);

    $submitted = app(SubmitExpenseClaim::class)->handle($claim, 'emp:1');
    expect($submitted->status)->toBe(ClaimStatus::Submitted)
        ->and($submitted->submitted_on)->not->toBeNull();

    $approved = app(DecideExpenseClaim::class)->handle($submitted, true, null, 'mgr:1');
    expect($approved->status)->toBe(ClaimStatus::Approved)
        ->and($approved->approved_on)->not->toBeNull()
        ->and($approved->rejection_reason)->toBeNull();

    $reimbursed = app(ReimburseExpenseClaim::class)->handle($approved, 'fin:1');
    expect($reimbursed->status)->toBe(ClaimStatus::Reimbursed)
        ->and($reimbursed->reimbursed_on)->not->toBeNull();

    $posted = app(PostExpenseClaim::class)->handle($reimbursed, 'acct:1');
    expect($posted->status)->toBe(ClaimStatus::Posted)
        ->and($posted->posted_on)->not->toBeNull()
        ->and($posted->history()->count())->toBe(5);

    // Rejection flow
    $claim2 = app(CreateExpenseClaim::class)->handle([
        'team_id' => 8,
        'employee_ref' => 'EMP-002',
        'claim_ref' => 'CLM-2026-002',
        'currency' => 'usd',
    ]);
    app(AddExpenseItem::class)->handle($claim2, [
        'category_ref' => 'OFFICE',
        'spent_on' => '2026-03-12',
        'description' => 'Unapproved gadget',
        'amount' => 99.00,
    ]);
    $claim2 = app(SubmitExpenseClaim::class)->handle($claim2, 'emp:2');
    $rejected = app(DecideExpenseClaim::class)->handle($claim2, false, 'Missing receipt', 'mgr:2');
    expect($rejected->status)->toBe(ClaimStatus::Rejected)
        ->and($rejected->approved_on)->toBeNull()
        ->and($rejected->rejection_reason)->toBe('Missing receipt');

    Event::assertDispatched(ClaimLifecycleChanged::class, function (ClaimLifecycleChanged $e) use ($posted): bool {
        return $e->claim->is($posted) && $e->event === 'posted' && $e->actorReference === 'acct:1';
    });

    // Query filters and perPage bounds
    $allPage = $query->paginate(null, null, 200);
    expect($allPage->total())->toBe(2)
        ->and($allPage->perPage())->toBe(100);

    $filteredPage = $query->paginate(7, ClaimStatus::Posted, 0);
    expect($filteredPage->total())->toBe(1)
        ->and($filteredPage->perPage())->toBe(1)
        ->and($filteredPage->items()[0]->is($posted))->toBeTrue();
});

it('enforces validation and state transitions across employee expense actions', function (): void {
    $create = app(CreateExpenseClaim::class);
    $valid = [
        'team_id' => 1,
        'employee_ref' => 'EMP-ERR',
        'claim_ref' => 'CLM-ERR-1',
        'currency' => 'EUR',
    ];

    foreach (['employee_ref', 'claim_ref', 'currency'] as $required) {
        expect(fn () => $create->handle(array_diff_key($valid, [$required => true])))
            ->toThrow(InvalidClaim::class, "Missing claim field [{$required}].");
    }

    expect(fn () => $create->handle([...$valid, 'currency' => 'EURO']))
        ->toThrow(InvalidClaim::class, 'ISO three-letter code');

    $draft = $create->handle($valid);

    $addItem = app(AddExpenseItem::class);
    $validItem = [
        'category_ref' => 'TRAVEL',
        'spent_on' => '2026-04-01',
        'description' => 'Taxi',
        'amount' => 25.00,
    ];

    foreach (['category_ref', 'spent_on', 'description', 'amount'] as $required) {
        expect(fn () => $addItem->handle($draft, array_diff_key($validItem, [$required => true])))
            ->toThrow(InvalidClaim::class, "Missing expense field [{$required}].");
    }

    expect(fn () => $addItem->handle($draft, [...$validItem, 'amount' => -5]))
        ->toThrow(InvalidClaim::class, 'amount must be positive')
        ->and(fn () => $addItem->handle($draft, [...$validItem, 'per_diem_days' => 0]))
        ->toThrow(InvalidClaim::class, 'Per diem days must be positive');

    // Invalid state transitions from Draft (with 0 items)
    expect(fn () => app(SubmitExpenseClaim::class)->handle($draft))->toThrow(InvalidClaim::class, 'draft claims with items')
        ->and(fn () => app(DecideExpenseClaim::class)->handle($draft, true))->toThrow(InvalidClaim::class, 'Only submitted claims')
        ->and(fn () => app(ReimburseExpenseClaim::class)->handle($draft))->toThrow(InvalidClaim::class, 'Only approved claims')
        ->and(fn () => app(PostExpenseClaim::class)->handle($draft))->toThrow(InvalidClaim::class, 'Only reimbursed claims');

    $addItem->handle($draft, $validItem);
    $submitted = app(SubmitExpenseClaim::class)->handle($draft);

    expect(fn () => $addItem->handle($submitted, $validItem))->toThrow(InvalidClaim::class, 'Only draft claims')
        ->and(fn () => app(SubmitExpenseClaim::class)->handle($submitted))->toThrow(InvalidClaim::class, 'draft claims with items')
        ->and(fn () => app(DecideExpenseClaim::class)->handle($submitted, false, ''))->toThrow(InvalidClaim::class, 'rejection reason is required');
});
