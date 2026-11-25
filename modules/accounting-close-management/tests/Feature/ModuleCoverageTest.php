<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Liberu\Accounting\CloseManagement\Actions\CertifyCloseCycle;
use Liberu\Accounting\CloseManagement\Actions\CreateCloseCycle;
use Liberu\Accounting\CloseManagement\Actions\LockCloseCycle;
use Liberu\Accounting\CloseManagement\Actions\RecordCloseEvidence;
use Liberu\Accounting\CloseManagement\Actions\ReopenCloseCycle;
use Liberu\Accounting\CloseManagement\Actions\UpdateCloseChecklist;
use Liberu\Accounting\CloseManagement\CloseManagementServiceProvider;
use Liberu\Accounting\CloseManagement\Enums\CloseCycleStatus;
use Liberu\Accounting\CloseManagement\Exceptions\InvalidCloseCycle;
use Liberu\Accounting\CloseManagement\Models\CloseCycle;
use Liberu\Accounting\CloseManagement\Queries\CloseCycleQuery;

it('boots the close management service provider and schema', function (): void {
    $provider = app()->getProvider(CloseManagementServiceProvider::class);
    expect($provider)->toBeInstanceOf(CloseManagementServiceProvider::class);
    $provider->boot();

    expect(Schema::hasTable('accounting_close_cycles'))->toBeTrue();

    expect(array_map(static fn (CloseCycleStatus $s): string => $s->value, CloseCycleStatus::cases()))
        ->toBe(['open', 'certified', 'locked', 'reopened']);

    $exception = new InvalidCloseCycle('Bad cycle');
    expect($exception)->toBeInstanceOf(DomainException::class)
        ->and($exception->getMessage())->toBe('Bad cycle');
});

it('creates a close cycle and validates required attributes', function (): void {
    $create = new CreateCloseCycle();

    foreach (['team_id', 'cycle_ref', 'period', 'due_date'] as $field) {
        $payload = [
            'team_id' => 1,
            'cycle_ref' => 'CLOSE-2026-08',
            'period' => '2026-08',
            'due_date' => '2026-09-05',
        ];
        unset($payload[$field]);

        expect(fn () => $create->handle($payload))
            ->toThrow(InvalidCloseCycle::class, "{$field} is required.");
    }

    $cycle = $create->handle([
        'team_id' => 1,
        'cycle_ref' => 'CLOSE-2026-08',
        'period' => '2026-08',
        'due_date' => '2026-09-05',
        'owners' => ['controller' => 'user-1'],
        'dependencies' => ['bank_reconciliation'],
        'reconciliations' => ['cash' => 'done'],
        'adjustments' => ['accruals' => 'posted'],
    ]);

    expect($cycle)->toBeInstanceOf(CloseCycle::class)
        ->and($cycle->status)->toBe(CloseCycleStatus::Open)
        ->and($cycle->due_date)->toBeInstanceOf(Carbon::class)
        ->and($cycle->due_date->toDateString())->toBe('2026-09-05')
        ->and($cycle->owners)->toBe(['controller' => 'user-1'])
        ->and($cycle->dependencies)->toBe(['bank_reconciliation'])
        ->and($cycle->reconciliations)->toBe(['cash' => 'done'])
        ->and($cycle->adjustments)->toBe(['accruals' => 'posted']);
});

it('executes full close lifecycle: checklist, evidence, certify, lock, and reopen with guards', function (): void {
    $cycle = (new CreateCloseCycle())->handle([
        'team_id' => 2,
        'cycle_ref' => 'CLOSE-2026-09',
        'period' => '2026-09',
        'due_date' => '2026-10-05',
    ]);

    $checklist = new UpdateCloseChecklist();
    $evidence = new RecordCloseEvidence();
    $certify = new CertifyCloseCycle();
    $lock = new LockCloseCycle();
    $reopen = new ReopenCloseCycle();

    // Cannot certify without checklist & evidence
    expect(fn () => $certify->handle($cycle, ['certifier_ref' => 'cfo-1']))
        ->toThrow(InvalidCloseCycle::class, 'Checklist, evidence, and certifier are required.');

    // Cannot lock before certification
    expect(fn () => $lock->handle($cycle))
        ->toThrow(InvalidCloseCycle::class, 'Only certified close cycles can be locked.');

    // Cannot reopen before locking
    expect(fn () => $reopen->handle($cycle, 'Need adjustment'))
        ->toThrow(InvalidCloseCycle::class, 'Only locked cycles can be reopened with a reason.');

    // Blank evidence reference rejected
    expect(fn () => $evidence->handle($cycle, ['file' => 'tb.pdf']))
        ->toThrow(InvalidCloseCycle::class, 'Evidence reference is required.');

    $cycle = $checklist->handle($cycle, [['task' => 'Trial balance', 'done' => true]]);
    // Still missing evidence
    expect(fn () => $certify->handle($cycle, ['certifier_ref' => 'cfo-1']))
        ->toThrow(InvalidCloseCycle::class);

    $cycle = $evidence->handle($cycle, ['reference' => 'EV-TB-09']);
    // Missing certifier_ref
    expect(fn () => $certify->handle($cycle, ['note' => 'ok']))
        ->toThrow(InvalidCloseCycle::class);

    $cycle = $certify->handle($cycle, ['certifier_ref' => 'cfo-1', 'signed_at' => '2026-10-04']);
    expect($cycle->status)->toBe(CloseCycleStatus::Certified)
        ->and($cycle->certification)->toBe(['certifier_ref' => 'cfo-1', 'signed_at' => '2026-10-04']);

    $cycle = $lock->handle($cycle);
    expect($cycle->status)->toBe(CloseCycleStatus::Locked);

    // Locked cycle cannot update checklist
    expect(fn () => $checklist->handle($cycle, [['task' => 'Mutate', 'done' => false]]))
        ->toThrow(InvalidCloseCycle::class, 'Locked close cycles cannot be changed.');

    // Blank reopen reason rejected
    expect(fn () => $reopen->handle($cycle, '   '))
        ->toThrow(InvalidCloseCycle::class, 'Only locked cycles can be reopened with a reason.');

    $reopened = $reopen->handle($cycle, 'Late supplier invoice');
    expect($reopened->status)->toBe(CloseCycleStatus::Reopened)
        ->and($reopened->review)->toBe(['reopen_reason' => 'Late supplier invoice']);
});

it('queries close cycles by team', function (): void {
    $query = new CloseCycleQuery();
    expect($query->forTeam(555))->toBeEmpty();

    $create = new CreateCloseCycle();
    $first = $create->handle(['team_id' => 15, 'cycle_ref' => 'C-1', 'period' => '2026-01', 'due_date' => '2026-02-05']);
    $second = $create->handle(['team_id' => 15, 'cycle_ref' => 'C-2', 'period' => '2026-02', 'due_date' => '2026-03-05']);
    $create->handle(['team_id' => 16, 'cycle_ref' => 'C-3', 'period' => '2026-03', 'due_date' => '2026-04-05']);

    $results = $query->forTeam(15);
    expect($results)->toHaveCount(2)
        ->and($results->pluck('id')->all())->toBe([$second->id, $first->id]);
});
