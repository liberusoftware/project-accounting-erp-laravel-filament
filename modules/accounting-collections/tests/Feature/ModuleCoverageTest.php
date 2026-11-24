<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Liberu\Accounting\Collections\Actions\CreateCollectionCase;
use Liberu\Accounting\Collections\Actions\PrepareCollectionStatement;
use Liberu\Accounting\Collections\Actions\RecordCollectionActivity;
use Liberu\Accounting\Collections\Actions\SendToCollectionAgency;
use Liberu\Accounting\Collections\Actions\WriteOffCollectionCase;
use Liberu\Accounting\Collections\CollectionsServiceProvider;
use Liberu\Accounting\Collections\Exceptions\InvalidCollectionCase;
use Liberu\Accounting\Collections\Models\CollectionCase;
use Liberu\Accounting\Collections\Queries\CollectionCaseQuery;

it('registers the collections service provider and runs migrations', function (): void {
    expect(app()->getProvider(CollectionsServiceProvider::class))
        ->toBeInstanceOf(CollectionsServiceProvider::class);

    expect(Schema::hasTable('accounting_collection_cases'))->toBeTrue();
    expect(Schema::hasColumns('accounting_collection_cases', [
        'id',
        'team_id',
        'case_ref',
        'customer_ref',
        'balance',
        'stage',
        'interest_rate',
        'reminders',
        'statement_run',
        'promise_to_pay',
        'disputes',
        'write_off',
        'agency',
    ]))->toBeTrue();
});

it('creates collection cases and validates required fields and non-negative amounts', function (): void {
    $action = new CreateCollectionCase();

    $case = $action->handle([
        'team_id' => 11,
        'case_ref' => 'COL-001',
        'customer_ref' => 'CUST-10',
        'balance' => 1250.5,
        'interest_rate' => 2.5,
    ])->refresh();

    expect($case)->toBeInstanceOf(CollectionCase::class)
        ->and($case->stage)->toBe('reminder')
        ->and($case->balance)->toBe('1250.50000000')
        ->and($case->interest_rate)->toBe('2.5000');

    foreach (['team_id', 'case_ref', 'customer_ref', 'balance'] as $missingField) {
        $payload = [
            'team_id' => 11,
            'case_ref' => 'COL-ERR',
            'customer_ref' => 'CUST-10',
            'balance' => 100,
        ];
        unset($payload[$missingField]);

        expect(fn () => $action->handle($payload))
            ->toThrow(InvalidCollectionCase::class, "{$missingField} is required.");
    }

    expect(fn () => $action->handle([
        'team_id' => 11,
        'case_ref' => 'COL-NEG-BAL',
        'customer_ref' => 'CUST-10',
        'balance' => -1,
    ]))->toThrow(InvalidCollectionCase::class, 'Balance and interest rate cannot be negative.');

    expect(fn () => $action->handle([
        'team_id' => 11,
        'case_ref' => 'COL-NEG-INT',
        'customer_ref' => 'CUST-10',
        'balance' => 100,
        'interest_rate' => -0.5,
    ]))->toThrow(InvalidCollectionCase::class, 'Balance and interest rate cannot be negative.');
});

it('records collection activities, statements, agency transfers, and write-offs', function (): void {
    $create = new CreateCollectionCase();
    $statement = new PrepareCollectionStatement();
    $activity = new RecordCollectionActivity();
    $sendAgency = new SendToCollectionAgency();
    $writeOff = new WriteOffCollectionCase();

    $case = $create->handle([
        'team_id' => 12,
        'case_ref' => 'COL-002',
        'customer_ref' => 'CUST-20',
        'balance' => 5000,
    ]);

    expect(fn () => $statement->handle($case, []))
        ->toThrow(InvalidCollectionCase::class, 'Statement period is required.');

    $case = $statement->handle($case, ['period' => '2026-08', 'total_due' => 5000]);
    expect($case->statement_run)->toBe(['period' => '2026-08', 'total_due' => 5000]);

    expect(fn () => $activity->reminder($case, []))
        ->toThrow(InvalidCollectionCase::class, 'Reminder schedule is required.');

    $case = $activity->reminder($case, ['scheduled_for' => '2026-09-01', 'channel' => 'email']);
    $case = $activity->reminder($case, ['scheduled_for' => '2026-09-08', 'channel' => 'sms']);
    expect($case->reminders)->toHaveCount(2);

    expect(fn () => $activity->promise($case, ['amount' => 1000]))
        ->toThrow(InvalidCollectionCase::class, 'Promise due date and amount are required.');
    expect(fn () => $activity->promise($case, ['due_on' => '2026-09-15']))
        ->toThrow(InvalidCollectionCase::class, 'Promise due date and amount are required.');

    $case = $activity->promise($case, ['due_on' => '2026-09-15', 'amount' => 2500]);
    expect($case->stage)->toBe('promise-to-pay')
        ->and($case->promise_to_pay)->toBe(['due_on' => '2026-09-15', 'amount' => 2500]);

    expect(fn () => $activity->dispute($case, []))
        ->toThrow(InvalidCollectionCase::class, 'Dispute reason is required.');

    $case = $activity->dispute($case, ['reason' => 'Damaged goods']);
    $case = $activity->dispute($case, ['reason' => 'Short shipment']);
    expect($case->stage)->toBe('dispute')
        ->and($case->disputes)->toHaveCount(2);

    expect(fn () => $sendAgency->handle($case, '   '))
        ->toThrow(InvalidCollectionCase::class, 'Agency adapter is required.');

    $case = $sendAgency->handle($case, 'acme-collections');
    expect($case->stage)->toBe('agency')
        ->and($case->agency['adapter'])->toBe('acme-collections')
        ->and($case->agency['sent_at'])->not->toBeEmpty();

    expect(fn () => $writeOff->handle($case, ['amount' => 100]))
        ->toThrow(InvalidCollectionCase::class, 'Write-off reason and positive amount are required.');
    expect(fn () => $writeOff->handle($case, ['reason' => 'Insolvent']))
        ->toThrow(InvalidCollectionCase::class, 'Write-off reason and positive amount are required.');
    expect(fn () => $writeOff->handle($case, ['reason' => 'Insolvent', 'amount' => 0]))
        ->toThrow(InvalidCollectionCase::class, 'Write-off reason and positive amount are required.');

    $case = $writeOff->handle($case, ['reason' => 'Bankruptcy', 'amount' => 5000]);
    expect($case->stage)->toBe('written-off')
        ->and($case->write_off)->toBe(['reason' => 'Bankruptcy', 'amount' => 5000]);
});

it('queries collection cases by team ordered by latest id', function (): void {
    $create = new CreateCollectionCase();
    $query = new CollectionCaseQuery();

    expect($query->forTeam(777))->toBeEmpty();

    $first = $create->handle([
        'team_id' => 15,
        'case_ref' => 'COL-A',
        'customer_ref' => 'CUST-1',
        'balance' => 100,
    ]);

    $second = $create->handle([
        'team_id' => 15,
        'case_ref' => 'COL-B',
        'customer_ref' => 'CUST-2',
        'balance' => 200,
    ]);

    $create->handle([
        'team_id' => 16,
        'case_ref' => 'COL-C',
        'customer_ref' => 'CUST-3',
        'balance' => 300,
    ]);

    $results = $query->forTeam(15);

    expect($results)->toHaveCount(2)
        ->and($results->pluck('id')->all())->toBe([$second->id, $first->id]);
});
