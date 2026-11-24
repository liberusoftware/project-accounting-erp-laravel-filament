<?php

declare(strict_types=1);

use Carbon\CarbonInterface;
use Liberu\Accounting\AccountReconciliations\AccountReconciliationsServiceProvider;
use Liberu\Accounting\AccountReconciliations\Actions\CarryForwardAccountReconciliation;
use Liberu\Accounting\AccountReconciliations\Actions\CertifyAccountReconciliation;
use Liberu\Accounting\AccountReconciliations\Actions\CreateAccountReconciliation;
use Liberu\Accounting\AccountReconciliations\Actions\PrepareAccountReconciliation;
use Liberu\Accounting\AccountReconciliations\Actions\ReviewAccountReconciliation;
use Liberu\Accounting\AccountReconciliations\Enums\ReconciliationStatus;
use Liberu\Accounting\AccountReconciliations\Exceptions\InvalidAccountReconciliation;
use Liberu\Accounting\AccountReconciliations\Models\AccountReconciliation;
use Liberu\Accounting\AccountReconciliations\Queries\AccountReconciliationQuery;

it('boots the service provider and covers enum, exception, model, lifecycle actions, and query', function (): void {
    $provider = $this->app->register(AccountReconciliationsServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(AccountReconciliationsServiceProvider::class);

    expect(array_map(fn (ReconciliationStatus $s): string => $s->value, ReconciliationStatus::cases()))
        ->toBe(['draft', 'prepared', 'in_review', 'certified', 'carried_forward']);

    $exception = new InvalidAccountReconciliation('Test message');
    expect($exception)->toBeInstanceOf(DomainException::class)
        ->and($exception->getMessage())->toBe('Test message');

    $create = app(CreateAccountReconciliation::class);
    $prepare = app(PrepareAccountReconciliation::class);
    $review = app(ReviewAccountReconciliation::class);
    $certify = app(CertifyAccountReconciliation::class);
    $carryForward = app(CarryForwardAccountReconciliation::class);
    $query = app(AccountReconciliationQuery::class);

    // Empty query check & null teamId check
    $emptyPage = $query->paginate(999);
    expect($emptyPage->total())->toBe(0);
    expect($query->paginate(null)->total())->toBe(0);

    // Create validation branches
    foreach (['team_id', 'book_id', 'account_id', 'period_start', 'period_end'] as $missingField) {
        $payload = [
            'team_id' => 1,
            'book_id' => 10,
            'account_id' => 100,
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
        ];
        unset($payload[$missingField]);
        expect(fn () => $create->handle($payload))
            ->toThrow(InvalidAccountReconciliation::class, "{$missingField} is required.");
    }

    expect(fn () => $create->handle([
        'team_id' => 1,
        'book_id' => 10,
        'account_id' => 100,
        'period_start' => '2026-02-01',
        'period_end' => '2026-01-31',
    ]))->toThrow(InvalidAccountReconciliation::class, 'The reconciliation period is invalid.');

    // Create happy path
    $rec = $create->handle([
        'team_id' => 1,
        'book_id' => 10,
        'account_id' => 100,
        'period_start' => '2026-01-01',
        'period_end' => '2026-01-31',
        'template' => ['name' => 'Bank Rec'],
        'aging' => ['0_30' => 100],
    ]);

    expect($rec->status)->toBe(ReconciliationStatus::Draft)
        ->and($rec->supporting_items)->toBe([])
        ->and($rec->template)->toBe(['name' => 'Bank Rec'])
        ->and($rec->aging)->toBe(['0_30' => 100])
        ->and($rec->period_start)->toBeInstanceOf(CarbonInterface::class)
        ->and($rec->period_end)->toBeInstanceOf(CarbonInterface::class)
        ->and($rec->isCertified())->toBeFalse();

    // Review/Certify/CarryForward fail before proper status
    expect(fn () => $review->handle($rec, ['user_id' => 2]))
        ->toThrow(InvalidAccountReconciliation::class, 'Only prepared reconciliations can enter review.');
    expect(fn () => $certify->handle($rec, ['user_id' => 3, 'attestation' => 'OK']))
        ->toThrow(InvalidAccountReconciliation::class, 'Only reviewed reconciliations with an attestation can be certified.');
    expect(fn () => $carryForward->handle($rec, ['period_start' => '2026-02-01', 'period_end' => '2026-02-28']))
        ->toThrow(InvalidAccountReconciliation::class, 'Only certified reconciliations can be carried forward with a target period.');

    // Prepare validation branches
    expect(fn () => $prepare->handle($rec, [], ['amount' => 100]))
        ->toThrow(InvalidAccountReconciliation::class, 'A preparer and source balance are required.');
    expect(fn () => $prepare->handle($rec, ['user_id' => 1], []))
        ->toThrow(InvalidAccountReconciliation::class, 'A preparer and source balance are required.');

    // Prepare happy path (including zero amount)
    $rec = $prepare->handle($rec, ['user_id' => 1, 'name' => 'Alice'], ['amount' => 0], [['ref' => 'TX-1', 'amount' => 0]]);
    expect($rec->status)->toBe(ReconciliationStatus::Prepared)
        ->and($rec->preparer)->toBe(['user_id' => 1, 'name' => 'Alice'])
        ->and($rec->source_balance)->toBe(['amount' => 0])
        ->and($rec->supporting_items)->toBe([['ref' => 'TX-1', 'amount' => 0]])
        ->and($rec->isCertified())->toBeFalse();

    // Review validation branch (missing user_id)
    expect(fn () => $review->handle($rec, []))
        ->toThrow(InvalidAccountReconciliation::class, 'Only prepared reconciliations can enter review.');

    // Review happy path
    $rec = $review->handle($rec, ['user_id' => 2, 'name' => 'Bob']);
    expect($rec->status)->toBe(ReconciliationStatus::InReview)
        ->and($rec->reviewer)->toBe(['user_id' => 2, 'name' => 'Bob']);

    // Certify validation branches
    expect(fn () => $certify->handle($rec, ['attestation' => 'OK']))
        ->toThrow(InvalidAccountReconciliation::class);
    expect(fn () => $certify->handle($rec, ['user_id' => 3]))
        ->toThrow(InvalidAccountReconciliation::class);

    // Certify happy path
    $rec = $certify->handle($rec, ['user_id' => 3, 'attestation' => 'Verified']);
    expect($rec->status)->toBe(ReconciliationStatus::Certified)
        ->and($rec->isCertified())->toBeTrue()
        ->and($rec->certification['user_id'])->toBe(3)
        ->and($rec->certification['attestation'])->toBe('Verified')
        ->and($rec->certification['certified_at'])->not->toBeEmpty();

    // CarryForward validation branches
    expect(fn () => $carryForward->handle($rec, ['period_end' => '2026-02-28']))
        ->toThrow(InvalidAccountReconciliation::class);
    expect(fn () => $carryForward->handle($rec, ['period_start' => '2026-02-01']))
        ->toThrow(InvalidAccountReconciliation::class);

    // CarryForward happy path
    $rec = $carryForward->handle($rec, ['period_start' => '2026-02-01', 'period_end' => '2026-02-28']);
    expect($rec->status)->toBe(ReconciliationStatus::CarriedForward)
        ->and($rec->carry_forward)->toBe(['period_start' => '2026-02-01', 'period_end' => '2026-02-28'])
        ->and($rec->isCertified())->toBeFalse();

    // Second reconciliation for query filtering and perPage clamping
    $rec2 = $create->handle([
        'team_id' => 1,
        'book_id' => 10,
        'account_id' => 101,
        'period_start' => '2026-02-01',
        'period_end' => '2026-02-28',
    ]);
    $create->handle([
        'team_id' => 2,
        'book_id' => 10,
        'account_id' => 102,
        'period_start' => '2026-02-01',
        'period_end' => '2026-02-28',
    ]);

    expect($query->paginate(1)->total())->toBe(2)
        ->and($query->paginate(1, ReconciliationStatus::Draft->value)->items())->toHaveCount(1)
        ->and($query->paginate(1, ReconciliationStatus::CarriedForward->value)->items())->toHaveCount(1)
        ->and($query->paginate(1, null, 0)->perPage())->toBe(1)
        ->and($query->paginate(1, null, 500)->perPage())->toBe(100)
        ->and(AccountReconciliation::find($rec2->id))->not->toBeNull();
});
