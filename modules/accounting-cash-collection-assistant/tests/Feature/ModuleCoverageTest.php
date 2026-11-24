<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Liberu\Accounting\CashCollectionAssistant\Actions\CreateCashCollectionAssistant;
use Liberu\Accounting\CashCollectionAssistant\Actions\RecordCollectionPromise;
use Liberu\Accounting\CashCollectionAssistant\Actions\ScheduleCollectionReminder;
use Liberu\Accounting\CashCollectionAssistant\Actions\SetCollectionDecision;
use Liberu\Accounting\CashCollectionAssistant\Actions\UpdateCashCollectionAssistant;
use Liberu\Accounting\CashCollectionAssistant\CashCollectionAssistantServiceProvider;
use Liberu\Accounting\CashCollectionAssistant\Exceptions\InvalidCashCollectionAssistant;
use Liberu\Accounting\CashCollectionAssistant\Models\CashCollectionAssistant;
use Liberu\Accounting\CashCollectionAssistant\Queries\CashCollectionAssistantQuery;

it('boots the cash collection assistant service provider and schema', function (): void {
    $provider = app()->getProvider(CashCollectionAssistantServiceProvider::class);
    expect($provider)->toBeInstanceOf(CashCollectionAssistantServiceProvider::class);
    $provider->boot();

    expect(Schema::hasTable('accounting_cash_collection_assistants'))->toBeTrue();

    $exception = new InvalidCashCollectionAssistant('Invalid assistant');
    expect($exception)->toBeInstanceOf(InvalidArgumentException::class)
        ->and($exception->getMessage())->toBe('Invalid assistant');
});

it('creates cash collection assistants with derived risk levels and validates inputs', function (): void {
    $create = new CreateCashCollectionAssistant();

    expect(fn () => $create->handle(['invoice_ref' => 'INV-1']))
        ->toThrow(InvalidCashCollectionAssistant::class, 'team_id is required.');
    expect(fn () => $create->handle(['team_id' => 1]))
        ->toThrow(InvalidCashCollectionAssistant::class, 'invoice_ref is required.');
    expect(fn () => $create->handle(['team_id' => 1, 'invoice_ref' => 'INV-1', 'risk_score' => -1]))
        ->toThrow(InvalidCashCollectionAssistant::class, 'Risk score must be between 0 and 100.');
    expect(fn () => $create->handle(['team_id' => 1, 'invoice_ref' => 'INV-1', 'risk_score' => 101]))
        ->toThrow(InvalidCashCollectionAssistant::class, 'Risk score must be between 0 and 100.');

    $normal = $create->handle(['team_id' => 1, 'invoice_ref' => 'INV-N', 'risk_score' => 20]);
    $medium = $create->handle(['team_id' => 1, 'invoice_ref' => 'INV-M', 'risk_score' => 40]);
    $high = $create->handle(['team_id' => 1, 'invoice_ref' => 'INV-H', 'risk_score' => 75]);
    $explicit = $create->handle(['team_id' => 1, 'invoice_ref' => 'INV-E', 'risk_score' => 10, 'risk_level' => 'custom']);

    expect($normal->risk_level)->toBe('normal')
        ->and($normal->risk_score)->toBe(20)
        ->and($medium->risk_level)->toBe('medium')
        ->and($high->risk_level)->toBe('high')
        ->and($explicit->risk_level)->toBe('custom');
});

it('updates assistant risk score and level and validates bounds', function (): void {
    $create = new CreateCashCollectionAssistant();
    $update = new UpdateCashCollectionAssistant();

    $assistant = $create->handle(['team_id' => 2, 'invoice_ref' => 'INV-200', 'risk_score' => 10]);

    expect(fn () => $update->handle($assistant, ['risk_score' => -5]))
        ->toThrow(InvalidCashCollectionAssistant::class, 'Risk score must be between 0 and 100.');
    expect(fn () => $update->handle($assistant, ['risk_score' => 150]))
        ->toThrow(InvalidCashCollectionAssistant::class, 'Risk score must be between 0 and 100.');

    $updatedMedium = $update->handle($assistant, ['risk_score' => 55, 'customer_ref' => 'CUST-9']);
    expect($updatedMedium->risk_level)->toBe('medium')
        ->and($updatedMedium->customer_ref)->toBe('CUST-9');

    $updatedHigh = $update->handle($updatedMedium, ['risk_score' => 90]);
    expect($updatedHigh->risk_level)->toBe('high');

    $updatedNormal = $update->handle($updatedHigh, ['risk_score' => 15]);
    expect($updatedNormal->risk_level)->toBe('normal');

    $updatedWithoutScore = $update->handle($updatedNormal, ['policy_ref' => 'POL-1']);
    expect($updatedWithoutScore->policy_ref)->toBe('POL-1')
        ->and($updatedWithoutScore->risk_level)->toBe('normal');
});

it('records promises, schedules reminders, and sets collection decisions', function (): void {
    $assistant = (new CreateCashCollectionAssistant())->handle([
        'team_id' => 3,
        'invoice_ref' => 'INV-300',
        'metadata' => ['channel' => 'email'],
    ]);

    $withPromise = (new RecordCollectionPromise())->handle($assistant, '2026-10-01', '1500.25000000');
    expect($withPromise->promised_date)->toBeInstanceOf(Carbon::class)
        ->and($withPromise->promised_date->toDateString())->toBe('2026-10-01')
        ->and($withPromise->promised_amount)->toBe('1500.25000000')
        ->and($withPromise->promise_status)->toBe('open');

    $withReminder = (new ScheduleCollectionReminder())->handle($withPromise, '2026-09-25 09:30:00', 'Friendly reminder');
    expect($withReminder->reminder_status)->toBe('scheduled')
        ->and($withReminder->reminder_at)->toBeInstanceOf(Carbon::class)
        ->and($withReminder->metadata)->toBe(['channel' => 'email', 'reminder_draft' => 'Friendly reminder']);

    $decisions = new SetCollectionDecision();
    $approved = $decisions->handle($withReminder, 'approval_status', 'approved');
    $kept = $decisions->handle($approved, 'promise_status', 'kept');
    $paid = $decisions->handle($kept, 'outcome', 'paid');
    $ignoredInvalidValue = $decisions->handle($paid, 'outcome', 'invalid_outcome');
    $ignoredInvalidField = $decisions->handle($ignoredInvalidValue, 'unknown_field', 'paid');

    expect($ignoredInvalidField->approval_status)->toBe('approved')
        ->and($ignoredInvalidField->promise_status)->toBe('kept')
        ->and($ignoredInvalidField->outcome)->toBe('paid');
});

it('queries collection assistants ordered by risk score descending', function (): void {
    $query = new CashCollectionAssistantQuery();
    expect($query->forTeam(999))->toBeEmpty();

    $create = new CreateCashCollectionAssistant();
    $low = $create->handle(['team_id' => 4, 'invoice_ref' => 'INV-A', 'risk_score' => 15]);
    $high = $create->handle(['team_id' => 4, 'invoice_ref' => 'INV-B', 'risk_score' => 85]);
    $mid = $create->handle(['team_id' => 4, 'invoice_ref' => 'INV-C', 'risk_score' => 50]);
    $create->handle(['team_id' => 5, 'invoice_ref' => 'INV-OTHER', 'risk_score' => 99]);

    $results = $query->forTeam(4);
    expect($results)->toHaveCount(3)
        ->and($results->pluck('id')->all())->toBe([$high->id, $mid->id, $low->id]);
});
