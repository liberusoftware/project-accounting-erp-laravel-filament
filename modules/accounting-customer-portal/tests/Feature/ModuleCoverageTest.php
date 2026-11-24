<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Liberu\Accounting\CustomerPortal\Actions\CreateCustomerPortalRecord;
use Liberu\Accounting\CustomerPortal\Actions\PublishCustomerPortalRecord;
use Liberu\Accounting\CustomerPortal\Actions\ResolveCustomerPortalDispute;
use Liberu\Accounting\CustomerPortal\CustomerPortalServiceProvider;
use Liberu\Accounting\CustomerPortal\Enums\CustomerPortalRecordType;
use Liberu\Accounting\CustomerPortal\Enums\CustomerPortalStatus;
use Liberu\Accounting\CustomerPortal\Exceptions\InvalidCustomerPortalRecord;
use Liberu\Accounting\CustomerPortal\Models\CustomerPortalRecord;
use Liberu\Accounting\CustomerPortal\Queries\CustomerPortalQuery;

it('registers and boots the customer portal service provider and migrations', function (): void {
    $provider = app()->getProvider(CustomerPortalServiceProvider::class)
        ?? new CustomerPortalServiceProvider(app());

    $provider->register();
    $provider->boot();

    expect($provider)->toBeInstanceOf(CustomerPortalServiceProvider::class)
        ->and(Schema::hasTable('accounting_customer_portal_records'))->toBeTrue();
});

it('exposes customer portal enums and exception class', function (): void {
    expect(array_map(static fn (CustomerPortalRecordType $t): string => $t->value, CustomerPortalRecordType::cases()))
        ->toBe(['estimate', 'invoice', 'credit', 'statement', 'payment_link', 'payment', 'dispute', 'document', 'preference'])
        ->and(array_map(static fn (CustomerPortalStatus $s): string => $s->value, CustomerPortalStatus::cases()))
        ->toBe(['draft', 'published', 'paid', 'disputed', 'resolved', 'archived']);

    $exception = new InvalidCustomerPortalRecord('Invalid portal record.');
    expect($exception)->toBeInstanceOf(DomainException::class)
        ->and($exception->getMessage())->toBe('Invalid portal record.');
});

it('creates customer portal records with enum/string types, casts, and duplicate checks', function (): void {
    $action = app(CreateCustomerPortalRecord::class);

    $record = $action->handle([
        'team_id' => 10,
        'customer_id' => 'cust-10',
        'type' => CustomerPortalRecordType::Invoice,
        'reference' => 'INV-100',
        'currency' => 'GBP',
        'amount' => 320.45,
        'payload' => ['lines' => 3],
        'metadata' => ['source' => 'erp'],
    ]);

    expect($record)->toBeInstanceOf(CustomerPortalRecord::class)
        ->and($record->type)->toBe(CustomerPortalRecordType::Invoice)
        ->and($record->status)->toBe(CustomerPortalStatus::Draft)
        ->and((float) $record->amount)->toBe(320.45)
        ->and($record->payload)->toBe(['lines' => 3])
        ->and($record->metadata)->toBe(['source' => 'erp']);

    // Zero amount and string type with explicit status work; same reference under different type works.
    $dispute = $action->handle([
        'team_id' => 10,
        'customer_id' => 'cust-10',
        'type' => 'dispute',
        'reference' => 'INV-100',
        'status' => CustomerPortalStatus::Disputed,
        'amount' => 0,
    ]);
    expect($dispute->type)->toBe(CustomerPortalRecordType::Dispute)
        ->and($dispute->status)->toBe(CustomerPortalStatus::Disputed);

    // Duplicate team_id + type + reference throws.
    expect(fn () => $action->handle([
        'team_id' => 10,
        'customer_id' => 'cust-99',
        'type' => 'invoice',
        'reference' => 'INV-100',
    ]))->toThrow(InvalidCustomerPortalRecord::class, 'The portal reference already exists.');
});

it('rejects invalid customer portal record attributes', function (array $override, string $expectedMessage): void {
    $base = [
        'team_id' => 1,
        'customer_id' => 'cust-1',
        'type' => 'statement',
        'reference' => 'STMT-1',
        'amount' => 10,
    ];

    $payload = array_merge($base, $override);
    foreach ($override as $key => $val) {
        if ($val === null) {
            unset($payload[$key]);
        }
    }

    expect(fn () => app(CreateCustomerPortalRecord::class)->handle($payload))
        ->toThrow(InvalidCustomerPortalRecord::class, $expectedMessage);
})->with([
    'missing type' => [['type' => null], 'A valid customer portal record type is required.'],
    'invalid type' => [['type' => 'unknown_type'], 'A valid customer portal record type is required.'],
    'missing team_id' => [['team_id' => null], 'team_id is required.'],
    'blank customer_id' => [['customer_id' => ''], 'customer_id is required.'],
    'blank reference' => [['reference' => '  '], 'reference is required.'],
    'negative amount' => [['amount' => -0.01], 'Amount must not be negative.'],
]);

it('publishes draft records and resolves active disputes with proper state guards', function (): void {
    Carbon::setTestNow('2026-08-29 14:30:00');

    $create = app(CreateCustomerPortalRecord::class);
    $publish = app(PublishCustomerPortalRecord::class);
    $resolve = app(ResolveCustomerPortalDispute::class);

    $draft = $create->handle([
        'team_id' => 20,
        'customer_id' => 'cust-20',
        'type' => CustomerPortalRecordType::Statement,
        'reference' => 'STMT-20',
    ]);

    $published = $publish->handle($draft);
    expect($published->status)->toBe(CustomerPortalStatus::Published)
        ->and($published->published_at?->toDateTimeString())->toBe('2026-08-29 14:30:00');

    expect(fn () => $publish->handle($published))
        ->toThrow(InvalidCustomerPortalRecord::class, 'Only draft records can be published.');

    // Resolving a non-dispute record throws even if status is Disputed.
    $nonDisputeDisputedStatus = $create->handle([
        'team_id' => 20,
        'customer_id' => 'cust-20',
        'type' => CustomerPortalRecordType::Invoice,
        'reference' => 'INV-20',
        'status' => CustomerPortalStatus::Disputed,
    ]);
    expect(fn () => $resolve->handle($nonDisputeDisputedStatus, ['outcome' => 'closed']))
        ->toThrow(InvalidCustomerPortalRecord::class, 'Only active disputes can be resolved.');

    // Resolving a dispute not in Disputed status throws.
    $draftDispute = $create->handle([
        'team_id' => 20,
        'customer_id' => 'cust-20',
        'type' => CustomerPortalRecordType::Dispute,
        'reference' => 'DSP-20-DRAFT',
    ]);
    expect(fn () => $resolve->handle($draftDispute, ['outcome' => 'closed']))
        ->toThrow(InvalidCustomerPortalRecord::class, 'Only active disputes can be resolved.');

    // Active dispute resolves and merges resolution into existing metadata.
    $activeDispute = $create->handle([
        'team_id' => 20,
        'customer_id' => 'cust-20',
        'type' => CustomerPortalRecordType::Dispute,
        'reference' => 'DSP-20-ACTIVE',
        'status' => CustomerPortalStatus::Disputed,
        'metadata' => ['opened_by' => 'customer'],
    ]);

    $resolved = $resolve->handle($activeDispute, ['outcome' => 'credit_issued', 'by' => 'agent-1']);
    expect($resolved->status)->toBe(CustomerPortalStatus::Resolved)
        ->and($resolved->metadata)->toBe([
            'opened_by' => 'customer',
            'resolution' => ['outcome' => 'credit_issued', 'by' => 'agent-1'],
        ]);

    Carbon::setTestNow();
});

it('queries customer portal records using model scope and team isolation', function (): void {
    $query = app(CustomerPortalQuery::class);

    expect($query->forTeam(888))->toBeEmpty()
        ->and($query->forCustomer(888, 'cust-none'))->toBeEmpty();

    $create = app(CreateCustomerPortalRecord::class);

    $r1 = $create->handle([
        'team_id' => 30,
        'customer_id' => 'cust-alpha',
        'type' => 'estimate',
        'reference' => 'EST-1',
    ]);
    $r2 = $create->handle([
        'team_id' => 30,
        'customer_id' => 'cust-beta',
        'type' => 'document',
        'reference' => 'DOC-1',
    ]);
    $create->handle([
        'team_id' => 31,
        'customer_id' => 'cust-alpha',
        'type' => 'estimate',
        'reference' => 'EST-2',
    ]);

    expect($query->forTeam(30)->pluck('id')->all())->toContain($r1->id, $r2->id)
        ->and($query->forTeam(30))->toHaveCount(2)
        ->and($query->forCustomer(30, 'cust-alpha')->pluck('id')->all())->toBe([$r1->id])
        ->and(CustomerPortalRecord::query()->forCustomer('cust-alpha')->count())->toBe(2);
});
