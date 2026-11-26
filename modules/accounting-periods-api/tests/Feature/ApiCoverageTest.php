<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Core\Models\Book;
use Liberu\Accounting\Core\Models\LegalEntity;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\Periods\Actions\UnlockPeriod;
use Liberu\Accounting\PeriodsApi\AccountingPeriodsApiServiceProvider;
use Liberu\Accounting\PeriodsApi\Http\Controllers\AccountingPeriodController;
use Liberu\Accounting\PeriodsApi\Http\Requests\StoreAccountingPeriodRequest;
use Liberu\Accounting\PeriodsApi\Policies\AccountingPeriodsPolicy;

it('covers accounting-periods-api provider, policy, request, controller, and resource', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(AccountingPeriodsApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(AccountingPeriodsApiServiceProvider::class);

    $formReq = new StoreAccountingPeriodRequest();
    expect($formReq->authorize())->toBeTrue();
    expect($formReq->rules())->toBeArray();

    $policy = new AccountingPeriodsPolicy();
    $allowUser = new class() extends User
    {
        public int $current_team_id = 1;

        public function tokenCan(string $a): bool
        {
            return true;
        }
    };
    $denyUser = new class() extends User
    {
        public int $current_team_id = 2;

        public function tokenCan(string $a): bool
        {
            return false;
        }
    };
    expect($policy->viewAny($allowUser))->toBeTrue();
    expect($policy->viewAny($denyUser))->toBeFalse();
    expect($policy->viewAny(null))->toBeFalse();
    expect($policy->view($allowUser))->toBeTrue();
    expect($policy->view($denyUser))->toBeFalse();
    expect($policy->view(null))->toBeFalse();
    expect($policy->create($allowUser))->toBeTrue();
    expect($policy->create($denyUser))->toBeFalse();
    expect($policy->create(null))->toBeFalse();
    expect($policy->update($allowUser))->toBeTrue();
    expect($policy->update($denyUser))->toBeFalse();
    expect($policy->update(null))->toBeFalse();

    $entity = LegalEntity::query()->create([
        'name' => 'Periods Entity',
        'currency_code' => 'GBP',
        'accounting_basis' => 'accrual',
        'is_active' => true,
    ]);
    $book = Book::query()->create([
        'legal_entity_id' => $entity->id,
        'name' => 'Main Book',
        'code' => 'MAIN',
        'accounting_basis' => 'accrual',
        'is_active' => true,
    ]);

    $p1 = $this->postJson('/api/v1/accounting/accounting-periods/periods', [
        'book_id' => $book->id,
        'starts_on' => '2026-01-01',
        'ends_on' => '2026-01-31',
    ]);
    $p1->assertCreated();
    $p1Id = $p1->json('data.id');

    // Overlapping period triggers InvalidArgumentException catch branch
    $this->postJson('/api/v1/accounting/accounting-periods/periods', [
        'book_id' => $book->id,
        'starts_on' => '2026-01-15',
        'ends_on' => '2026-02-15',
    ])->assertUnprocessable();

    $this->getJson("/api/v1/accounting/accounting-periods/periods?book_id={$book->id}")->assertOk();
    $this->getJson("/api/v1/accounting/accounting-periods/periods/{$p1Id}")->assertOk();
    $this->getJson("/api/v1/accounting/accounting-periods/periods/{$p1Id}/posting-allowed?date=2026-01-15")->assertOk();

    // Invalid transition/lock/unlock trigger InvalidPeriodTransition catch branches
    $this->postJson("/api/v1/accounting/accounting-periods/periods/{$p1Id}/transition", [
        'state' => 'hard_closed',
    ])->assertUnprocessable();
    $this->postJson("/api/v1/accounting/accounting-periods/periods/{$p1Id}/lock")->assertUnprocessable();
    // Unlocking an unlocked period is idempotent (200); cover unlock InvalidPeriodTransition catch branch via blank reason
    $this->postJson("/api/v1/accounting/accounting-periods/periods/{$p1Id}/unlock", [
        'reason' => 'Not locked yet',
    ])->assertOk();
    $blankReasonRequest = new class() extends Request
    {
        public function validate(array $rules, ...$params): array
        {
            return ['reason' => ''];
        }

        public function user($guard = null): mixed
        {
            return auth()->user();
        }
    };
    expect(fn () => (new AccountingPeriodController())->unlock($blankReasonRequest, (string) $p1Id, app(UnlockPeriod::class)))
        ->toThrow(ValidationException::class);

    // Valid lifecycle: open -> soft_closed -> hard_closed -> lock -> unlock
    $this->postJson("/api/v1/accounting/accounting-periods/periods/{$p1Id}/transition", [
        'state' => 'soft_closed',
        'reason' => 'Month end',
    ])->assertOk();
    $this->postJson("/api/v1/accounting/accounting-periods/periods/{$p1Id}/transition", [
        'state' => 'hard_closed',
        'reason' => 'Finalized',
    ])->assertOk();
    $this->postJson("/api/v1/accounting/accounting-periods/periods/{$p1Id}/lock")->assertOk();
    $this->postJson("/api/v1/accounting/accounting-periods/periods/{$p1Id}/unlock", [
        'reason' => 'Audit adjustment',
    ])->assertOk();

    // Create a second open period and delete it
    $p2 = $this->postJson('/api/v1/accounting/accounting-periods/periods', [
        'book_id' => $book->id,
        'starts_on' => '2026-02-01',
        'ends_on' => '2026-02-28',
    ]);
    $p2->assertCreated();
    $p2Id = $p2->json('data.id');
    $this->deleteJson("/api/v1/accounting/accounting-periods/periods/{$p2Id}")->assertNoContent();
});
