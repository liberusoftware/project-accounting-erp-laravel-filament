<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Liberu\Accounting\Core\Models\Book;
use Liberu\Accounting\Core\Models\LegalEntity;
use Liberu\Accounting\Policies\AccountingPoliciesServiceProvider;
use Liberu\Accounting\Policies\Actions\SavePolicyRule;
use Liberu\Accounting\Policies\Enums\PolicyCategory;
use Liberu\Accounting\Policies\Events\PolicyRuleSaved;
use Liberu\Accounting\Policies\Exceptions\InvalidPolicyRule;
use Liberu\Accounting\Policies\Models\PolicyRule;
use Liberu\Accounting\Policies\Queries\PolicyRuleForDate;

function createPolicyTestBook(string $code = 'MAIN'): Book
{
    $entity = LegalEntity::create([
        'name' => 'Policy Test Entity '.$code,
        'registration_number' => 'REG-'.$code.'-'.uniqid(),
        'currency_code' => 'GBP',
        'accounting_basis' => 'accrual',
        'is_active' => true,
    ]);

    return Book::create([
        'legal_entity_id' => $entity->id,
        'name' => 'Book '.$code,
        'code' => $code,
        'accounting_basis' => 'accrual',
        'is_active' => true,
    ]);
}

it('boots the service provider and loads the accounting policies table', function (): void {
    expect(app()->getProvider(AccountingPoliciesServiceProvider::class))
        ->toBeInstanceOf(AccountingPoliciesServiceProvider::class);

    expect(Schema::hasTable('accounting_policy_rules'))->toBeTrue();
});

it('exposes all policy category enum cases and values', function (): void {
    expect(array_map(fn (PolicyCategory $case): string => $case->value, PolicyCategory::cases()))->toBe([
        'recognition',
        'capitalization',
        'depreciation',
        'fx',
        'tax',
        'rounding',
        'write_off',
        'materiality',
        'approval',
    ]);
});

it('creates policy rules, updates non-overlapping rules, and dispatches PolicyRuleSaved', function (): void {
    $book = createPolicyTestBook('POL1');

    $dispatched = [];
    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('dispatch')->andReturnUsing(function (object $event) use (&$dispatched): void {
        $dispatched[] = $event;
    });

    Event::fake([PolicyRuleSaved::class]);

    $action = app(SavePolicyRule::class);

    $rule = $action->handle(null, [
        'book_id' => $book->id,
        'category' => PolicyCategory::Capitalization,
        'key' => 'asset_threshold',
        'value' => ['amount' => 1000, 'currency' => 'GBP'],
        'effective_from' => '2026-01-01',
        'effective_until' => '2026-06-30',
        'is_active' => true,
        'approved_by' => 'controller@example.com',
        'approved_at' => '2026-01-01 09:00:00',
        'metadata' => ['note' => 'H1 threshold'],
    ]);

    expect($rule)->toBeInstanceOf(PolicyRule::class)
        ->and($rule->category)->toBe(PolicyCategory::Capitalization)
        ->and($rule->value)->toBe(['amount' => 1000, 'currency' => 'GBP'])
        ->and($rule->metadata)->toBe(['note' => 'H1 threshold'])
        ->and($rule->is_active)->toBeTrue()
        ->and($rule->book->is($book))->toBeTrue()
        ->and($rule->isApproved())->toBeTrue();

    // Update the same rule without triggering a self-overlap error
    $updated = $action->handle($rule, [
        'book_id' => $book->id,
        'category' => 'capitalization',
        'key' => 'asset_threshold',
        'value' => [],
        'effective_from' => '2026-01-01',
        'effective_until' => '2026-06-30',
    ]);

    expect($updated->id)->toBe($rule->id)
        ->and($updated->value)->toBe([]);

    // Non-overlapping subsequent period for the same book/category/key succeeds
    $nextRule = $action->handle(null, [
        'book_id' => $book->id,
        'category' => PolicyCategory::Capitalization,
        'key' => 'asset_threshold',
        'value' => ['amount' => 1500],
        'effective_from' => '2026-07-01',
        'effective_until' => null,
    ]);

    expect($nextRule->id)->not->toBe($rule->id)
        ->and($nextRule->isApproved())->toBeFalse();

    // Verify PolicyRuleSaved event construction and direct dispatch
    $event = new PolicyRuleSaved($updated);
    expect($event->rule->is($updated))->toBeTrue();
    PolicyRuleSaved::dispatch($updated);
    Event::assertDispatched(PolicyRuleSaved::class, fn (PolicyRuleSaved $e): bool => $e->rule->is($updated));

    // Also verify SavePolicyRule schedules afterCommit dispatch when transaction commits
    $customAction = new SavePolicyRule($dispatcher);
    DB::shouldReceive('transaction')->once()->andReturnUsing(fn (callable $cb) => $cb());
    DB::shouldReceive('afterCommit')->once()->andReturnUsing(fn (callable $cb) => $cb());

    $dispatchedRule = $customAction->handle(null, [
        'book_id' => $book->id,
        'category' => PolicyCategory::Rounding,
        'key' => 'precision',
        'value' => ['decimals' => 2],
        'effective_from' => '2026-01-01',
    ]);

    expect($dispatched)->toHaveCount(1)
        ->and($dispatched[0])->toBeInstanceOf(PolicyRuleSaved::class)
        ->and($dispatched[0]->rule->is($dispatchedRule))->toBeTrue();
});

it('rejects invalid or overlapping policy rules with InvalidPolicyRule', function (): void {
    $book = createPolicyTestBook('POL2');
    $action = app(SavePolicyRule::class);

    foreach (['book_id', 'category', 'key', 'value', 'effective_from'] as $missing) {
        $payload = [
            'book_id' => $book->id,
            'category' => 'tax',
            'key' => 'vat_mode',
            'value' => ['mode' => 'standard'],
            'effective_from' => '2026-01-01',
        ];
        unset($payload[$missing]);

        expect(fn () => $action->handle(null, $payload))
            ->toThrow(InvalidPolicyRule::class, "Policy rule {$missing} is required.");
    }

    expect(fn () => $action->handle(null, [
        'book_id' => $book->id,
        'category' => 'tax',
        'key' => '',
        'value' => ['mode' => 'standard'],
        'effective_from' => '2026-01-01',
    ]))->toThrow(InvalidPolicyRule::class, 'Policy rule key is required.');

    expect(fn () => $action->handle(null, [
        'book_id' => $book->id,
        'category' => 'tax',
        'key' => 'vat_mode',
        'value' => 'not-an-array',
        'effective_from' => '2026-01-01',
    ]))->toThrow(InvalidPolicyRule::class, 'Policy rule value must be an array.');

    expect(fn () => $action->handle(null, [
        'book_id' => $book->id,
        'category' => 'unsupported_category',
        'key' => 'vat_mode',
        'value' => ['mode' => 'standard'],
        'effective_from' => '2026-01-01',
    ]))->toThrow(InvalidPolicyRule::class, 'Policy rule category is not supported.');

    expect(fn () => $action->handle(null, [
        'book_id' => $book->id,
        'category' => 'tax',
        'key' => 'vat_mode',
        'value' => ['mode' => 'standard'],
        'effective_from' => '2026-05-01',
        'effective_until' => '2026-04-01',
    ]))->toThrow(InvalidPolicyRule::class, 'Policy effective end must not precede its start.');

    $existing = $action->handle(null, [
        'book_id' => $book->id,
        'category' => 'tax',
        'key' => 'vat_mode',
        'value' => ['mode' => 'standard'],
        'effective_from' => '2026-03-01',
        'effective_until' => '2026-06-30',
    ]);

    // Overlapping new rule
    expect(fn () => $action->handle(null, [
        'book_id' => $book->id,
        'category' => 'tax',
        'key' => 'vat_mode',
        'value' => ['mode' => 'cash'],
        'effective_from' => '2026-06-01',
        'effective_until' => '2026-12-31',
    ]))->toThrow(InvalidPolicyRule::class, 'Effective-dated policy rules may not overlap');

    // Open-ended rule also rejects overlapping earlier rule
    $second = $action->handle(null, [
        'book_id' => $book->id,
        'category' => 'tax',
        'key' => 'vat_mode',
        'value' => ['mode' => 'flat'],
        'effective_from' => '2026-07-01',
        'effective_until' => null,
    ]);

    expect(fn () => $action->handle($existing, [
        'book_id' => $book->id,
        'category' => 'tax',
        'key' => 'vat_mode',
        'value' => ['mode' => 'standard'],
        'effective_from' => '2026-03-01',
        'effective_until' => '2026-07-15',
    ]))->toThrow(InvalidPolicyRule::class, 'Effective-dated policy rules may not overlap');

    expect($second->id)->not->toBe($existing->id);
});

it('evaluates PolicyRule::appliesOn, isApproved, and queries PolicyRuleForDate', function (): void {
    $book = createPolicyTestBook('POL3');
    $otherBook = createPolicyTestBook('POL4');

    $bounded = PolicyRule::create([
        'book_id' => $book->id,
        'category' => PolicyCategory::Depreciation,
        'key' => 'method',
        'value' => ['type' => 'straight_line'],
        'effective_from' => '2026-01-01',
        'effective_until' => '2026-03-31',
        'is_active' => true,
        'approved_by' => 'cfo',
        'approved_at' => null,
    ]);

    $openEnded = PolicyRule::create([
        'book_id' => $book->id,
        'category' => PolicyCategory::Depreciation,
        'key' => 'method',
        'value' => ['type' => 'reducing_balance'],
        'effective_from' => '2026-04-01',
        'effective_until' => null,
        'is_active' => true,
        'approved_by' => null,
        'approved_at' => now(),
    ]);

    $inactive = PolicyRule::create([
        'book_id' => $book->id,
        'category' => PolicyCategory::Depreciation,
        'key' => 'method',
        'value' => ['type' => 'disabled'],
        'effective_from' => '2026-05-01',
        'effective_until' => null,
        'is_active' => false,
    ]);

    PolicyRule::create([
        'book_id' => $otherBook->id,
        'category' => PolicyCategory::Depreciation,
        'key' => 'method',
        'value' => ['type' => 'other_book'],
        'effective_from' => '2026-01-01',
        'is_active' => true,
    ]);

    expect($bounded->isApproved())->toBeFalse()
        ->and($openEnded->isApproved())->toBeFalse()
        ->and($bounded->appliesOn('2025-12-31'))->toBeFalse()
        ->and($bounded->appliesOn('2026-01-01'))->toBeTrue()
        ->and($bounded->appliesOn(CarbonImmutable::parse('2026-03-31')))->toBeTrue()
        ->and($bounded->appliesOn('2026-04-01'))->toBeFalse()
        ->and($openEnded->appliesOn('2026-04-01'))->toBeTrue()
        ->and($openEnded->appliesOn('2030-01-01'))->toBeTrue()
        ->and($inactive->appliesOn('2026-06-01'))->toBeFalse();

    $query = app(PolicyRuleForDate::class);

    expect($query->handle($book->id, PolicyCategory::Depreciation, 'method', '2025-12-15'))->toBeNull()
        ->and($query->handle($book->id, PolicyCategory::Depreciation, 'method', '2026-02-15')?->is($bounded))->toBeTrue()
        ->and($query->handle($book->id, 'depreciation', 'method', CarbonImmutable::parse('2026-08-15'))?->is($openEnded))->toBeTrue()
        ->and($query->handle($book->id, PolicyCategory::Fx, 'method', '2026-02-15'))->toBeNull();
});
