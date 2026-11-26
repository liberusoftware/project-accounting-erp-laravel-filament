<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\AccountReconciliationsApi\AccountReconciliationsApiServiceProvider;
use Liberu\Accounting\AccountReconciliationsApi\Policies\AccountReconciliationsPolicy;
use Liberu\Accounting\Erp\Models\User;

it('covers accounting-account-reconciliations-api provider, policy, controller, and resource', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(AccountReconciliationsApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(AccountReconciliationsApiServiceProvider::class);

    $policy = new AccountReconciliationsPolicy();
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
    $wrongTeamUser = new class() extends User
    {
        public int $current_team_id = 2;

        public function tokenCan(string $a): bool
        {
            return true;
        }
    };
    $record = (object) ['team_id' => 1];

    expect($policy->viewAny($allowUser))->toBeTrue();
    expect($policy->viewAny($denyUser))->toBeFalse();
    expect($policy->viewAny(null))->toBeFalse();
    expect($policy->view($allowUser, $record))->toBeTrue();
    expect($policy->view($wrongTeamUser, $record))->toBeFalse();
    expect($policy->view($denyUser, $record))->toBeFalse();
    expect($policy->view(null, $record))->toBeFalse();
    expect($policy->create($allowUser))->toBeTrue();
    expect($policy->create($denyUser))->toBeFalse();
    expect($policy->create(null))->toBeFalse();
    expect($policy->update($allowUser, $record))->toBeTrue();
    expect($policy->update($wrongTeamUser, $record))->toBeFalse();
    expect($policy->update($denyUser, $record))->toBeFalse();
    expect($policy->update(null, $record))->toBeFalse();

    $storeRes = $this->postJson('/api/v1/accounting/account-reconciliations', [
        'book_id' => 1,
        'account_id' => 101,
        'period_start' => '2026-01-01',
        'period_end' => '2026-01-31',
        'template' => ['name' => 'Bank Reconciliation'],
    ]);
    expect($storeRes->status())->toBeLessThan(300);
    $id = $storeRes->json('data.id');

    expect($this->getJson('/api/v1/accounting/account-reconciliations')->status())->toBe(200);
    expect($this->getJson('/api/v1/accounting/account-reconciliations?status=draft')->status())->toBe(200);
    expect($this->getJson("/api/v1/accounting/account-reconciliations/{$id}")->status())->toBe(200);

    $prepareRes = $this->postJson("/api/v1/accounting/account-reconciliations/{$id}/prepare", [
        'source_balance' => ['amount' => 1250.50, 'currency' => 'USD'],
        'supporting_items' => [['description' => 'Deposit in transit', 'amount' => 250.50]],
    ]);
    expect($prepareRes->status())->toBe(200);

    $reviewRes = $this->postJson("/api/v1/accounting/account-reconciliations/{$id}/review", [
        'comment' => 'Reviewed and verified.',
    ]);
    expect($reviewRes->status())->toBe(200);

    $certifyRes = $this->postJson("/api/v1/accounting/account-reconciliations/{$id}/certify", [
        'attestation' => 'I certify this reconciliation is complete and accurate.',
    ]);
    expect($certifyRes->status())->toBe(200);

    $carryForwardRes = $this->postJson("/api/v1/accounting/account-reconciliations/{$id}/carry-forward", [
        'period_start' => '2026-02-01',
        'period_end' => '2026-02-28',
        'amount' => 1250.50,
    ]);
    expect($carryForwardRes->status())->toBe(200);
});
