<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Liberu\Accounting\CreditNotesAndAdjustments\Actions\AllocateCreditNote;
use Liberu\Accounting\CreditNotesAndAdjustments\Actions\ApproveCreditNote;
use Liberu\Accounting\CreditNotesAndAdjustments\Actions\CreateCreditNote;
use Liberu\Accounting\CreditNotesAndAdjustments\CreditNotesAndAdjustmentsServiceProvider;
use Liberu\Accounting\CreditNotesAndAdjustments\Enums\CreditNoteStatus;
use Liberu\Accounting\CreditNotesAndAdjustments\Exceptions\InvalidCreditNote;
use Liberu\Accounting\CreditNotesAndAdjustments\Models\CreditNote;
use Liberu\Accounting\CreditNotesAndAdjustments\Models\CreditNoteAllocation;
use Liberu\Accounting\CreditNotesAndAdjustments\Queries\CreditNoteQuery;

it('registers and boots the credit notes service provider and migrations', function (): void {
    $provider = app()->getProvider(CreditNotesAndAdjustmentsServiceProvider::class)
        ?? new CreditNotesAndAdjustmentsServiceProvider(app());

    $provider->register();
    $provider->boot();

    expect($provider)->toBeInstanceOf(CreditNotesAndAdjustmentsServiceProvider::class)
        ->and(Schema::hasTable('accounting_credit_notes'))->toBeTrue()
        ->and(Schema::hasTable('accounting_credit_note_allocations'))->toBeTrue();
});

it('exposes all credit note status enum cases and exception hierarchy', function (): void {
    expect(array_map(static fn (CreditNoteStatus $case): string => $case->value, CreditNoteStatus::cases()))
        ->toBe(['draft', 'approved', 'partially_allocated', 'allocated', 'refunded'])
        ->and(CreditNoteStatus::from('partially_allocated'))->toBe(CreditNoteStatus::PartiallyAllocated);

    $exception = new InvalidCreditNote('Invalid credit note state.');
    expect($exception)->toBeInstanceOf(DomainException::class)
        ->and($exception->getMessage())->toBe('Invalid credit note state.');
});

it('creates a draft credit note with casts and validates required fields and duplicates', function (): void {
    $action = app(CreateCreditNote::class);

    $note = $action->handle([
        'team_id' => 10,
        'customer_id' => 'cust-001',
        'credit_ref' => 'CN-2026-001',
        'reason' => 'Damaged goods return',
        'currency' => 'GBP',
        'amount' => 150.50,
        'tax_amount' => 25.08,
        'refund_reference' => 'REF-001',
        'store_credit_reference' => 'SC-001',
        'evidence' => ['ticket' => 'ZD-42'],
        'metadata' => ['channel' => 'portal'],
    ]);

    expect($note)->toBeInstanceOf(CreditNote::class)
        ->and($note->status)->toBe(CreditNoteStatus::Draft)
        ->and((float) $note->amount)->toBe(150.50)
        ->and((float) $note->allocated_amount)->toBe(0.0)
        ->and((float) $note->tax_amount)->toBe(25.08)
        ->and($note->refund_reference)->toBe('REF-001')
        ->and($note->store_credit_reference)->toBe('SC-001')
        ->and($note->evidence)->toBe(['ticket' => 'ZD-42'])
        ->and($note->metadata)->toBe(['channel' => 'portal']);

    // Same credit_ref on a different team is permitted.
    $otherTeamNote = $action->handle([
        'team_id' => 11,
        'customer_id' => 'cust-001',
        'credit_ref' => 'CN-2026-001',
        'reason' => 'Goodwill credit',
        'currency' => 'USD',
        'amount' => 50,
    ]);
    expect($otherTeamNote->team_id)->toBe(11);

    // Duplicate credit_ref for the same team throws.
    expect(fn () => $action->handle([
        'team_id' => 10,
        'customer_id' => 'cust-002',
        'credit_ref' => 'CN-2026-001',
        'reason' => 'Duplicate ref',
        'currency' => 'GBP',
        'amount' => 20,
    ]))->toThrow(InvalidCreditNote::class, 'Credit reference already exists.');
});

it('rejects missing required fields and non-positive amounts when creating a credit note', function (array $override, string $expectedMessage): void {
    $base = [
        'team_id' => 1,
        'customer_id' => 'cust-1',
        'credit_ref' => 'CN-100',
        'reason' => 'Billing adjustment',
        'currency' => 'EUR',
        'amount' => 100,
    ];

    $payload = array_merge($base, $override);
    foreach ($override as $key => $val) {
        if ($val === null) {
            unset($payload[$key]);
        }
    }

    expect(fn () => app(CreateCreditNote::class)->handle($payload))
        ->toThrow(InvalidCreditNote::class, $expectedMessage);
})->with([
    'missing team_id' => [['team_id' => null], 'team_id is required.'],
    'blank customer_id' => [['customer_id' => ''], 'customer_id is required.'],
    'blank credit_ref' => [['credit_ref' => '   '], 'credit_ref is required.'],
    'blank reason' => [['reason' => ''], 'reason is required.'],
    'blank currency' => [['currency' => ''], 'currency is required.'],
    'missing amount' => [['amount' => null], 'amount is required.'],
    'zero amount' => [['amount' => 0], 'Credit amount must be positive.'],
    'negative amount' => [['amount' => -15.25], 'Credit amount must be positive.'],
]);

it('approves a draft credit note and rejects non-draft approval', function (): void {
    Carbon::setTestNow('2026-08-29 12:00:00');

    $note = app(CreateCreditNote::class)->handle([
        'team_id' => 20,
        'customer_id' => 'cust-20',
        'credit_ref' => 'CN-20',
        'reason' => 'Overcharge',
        'currency' => 'USD',
        'amount' => 200,
    ]);

    $approved = app(ApproveCreditNote::class)->handle($note, 99);

    expect($approved->status)->toBe(CreditNoteStatus::Approved)
        ->and($approved->approved_by)->toBe(99)
        ->and($approved->approved_at?->toDateTimeString())->toBe('2026-08-29 12:00:00')
        ->and($approved->relationLoaded('allocations'))->toBeTrue();

    expect(fn () => app(ApproveCreditNote::class)->handle($approved, 100))
        ->toThrow(InvalidCreditNote::class, 'Only draft credits can be approved.');

    Carbon::setTestNow();
});

it('allocates credit notes partially and fully and enforces allocation rules', function (): void {
    $create = app(CreateCreditNote::class);
    $approve = app(ApproveCreditNote::class);
    $allocate = app(AllocateCreditNote::class);

    $draft = $create->handle([
        'team_id' => 30,
        'customer_id' => 'cust-30',
        'credit_ref' => 'CN-30',
        'reason' => 'Return',
        'currency' => 'GBP',
        'amount' => 100,
    ]);

    // Draft credit note cannot be allocated.
    expect(fn () => $allocate->handle($draft, 'INV-1', 50))
        ->toThrow(InvalidCreditNote::class, 'Credit must be approved and allocation must fit the remaining balance.');

    $note = $approve->handle($draft, 7);

    // Invalid invoice ref, non-positive amount, and exceeding balance all throw.
    expect(fn () => $allocate->handle($note, '  ', 10))
        ->toThrow(InvalidCreditNote::class)
        ->and(fn () => $allocate->handle($note, 'INV-1', 0))
        ->toThrow(InvalidCreditNote::class)
        ->and(fn () => $allocate->handle($note, 'INV-1', -5))
        ->toThrow(InvalidCreditNote::class)
        ->and(fn () => $allocate->handle($note, 'INV-1', 100.01))
        ->toThrow(InvalidCreditNote::class);

    // Partial allocation
    $first = $allocate->handle($note, 'INV-1', 40);
    $note->refresh();

    expect($first)->toBeInstanceOf(CreditNoteAllocation::class)
        ->and($first->creditNote->is($note))->toBeTrue()
        ->and((float) $first->amount)->toBe(40.0)
        ->and($note->status)->toBe(CreditNoteStatus::PartiallyAllocated)
        ->and((float) $note->allocated_amount)->toBe(40.0);

    // Exceeding remaining balance (60) throws.
    expect(fn () => $allocate->handle($note, 'INV-2', 65))
        ->toThrow(InvalidCreditNote::class);

    // Final allocation consumes remaining balance and transitions to Allocated.
    $second = $allocate->handle($note, 'INV-2', 60);
    $note->refresh();

    expect($second->invoice_ref)->toBe('INV-2')
        ->and($note->status)->toBe(CreditNoteStatus::Allocated)
        ->and((float) $note->allocated_amount)->toBe(100.0)
        ->and($note->allocations)->toHaveCount(2);
});

it('queries credit notes scoped by team with eager-loaded allocations', function (): void {
    $query = app(CreditNoteQuery::class);

    expect($query->forTeam(999))->toBeEmpty();

    $create = app(CreateCreditNote::class);
    $approve = app(ApproveCreditNote::class);
    $allocate = app(AllocateCreditNote::class);

    $n1 = $approve->handle($create->handle([
        'team_id' => 40,
        'customer_id' => 'c1',
        'credit_ref' => 'CN-40-1',
        'reason' => 'Discount',
        'currency' => 'USD',
        'amount' => 80,
    ]), 1);
    $allocate->handle($n1, 'INV-401', 30);

    $n2 = $create->handle([
        'team_id' => 40,
        'customer_id' => 'c2',
        'credit_ref' => 'CN-40-2',
        'reason' => 'Adjustment',
        'currency' => 'USD',
        'amount' => 25,
    ]);

    $create->handle([
        'team_id' => 41,
        'customer_id' => 'c3',
        'credit_ref' => 'CN-41-1',
        'reason' => 'Other team',
        'currency' => 'USD',
        'amount' => 10,
    ]);

    $results = $query->forTeam(40);

    expect($results)->toHaveCount(2)
        ->and($results->pluck('id')->all())->toContain($n1->id, $n2->id)
        ->and($results->every(fn (CreditNote $note): bool => $note->relationLoaded('allocations')))->toBeTrue();
});
