<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\AuditSupportApi\AuditSupportApiServiceProvider;
use Liberu\Accounting\AuditSupportApi\Policies\AuditSupportPolicy;
use Liberu\Accounting\Erp\Models\User;

it('covers accounting-audit-support-api provider, policy, controller, and resource', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(AuditSupportApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(AuditSupportApiServiceProvider::class);

    $policy = new AuditSupportPolicy();
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
    $noTeamUser = new class() extends User
    {
        public ?int $current_team_id = null;

        public function tokenCan(string $a): bool
        {
            return true;
        }
    };
    $record = (object) ['team_id' => 1];

    expect($policy->viewAny($allowUser))->toBeTrue();
    expect($policy->viewAny($denyUser))->toBeFalse();
    expect($policy->viewAny(null))->toBeFalse();
    expect($policy->create($allowUser))->toBeTrue();
    expect($policy->create($noTeamUser))->toBeFalse();
    expect($policy->create($denyUser))->toBeFalse();
    expect($policy->create(null))->toBeFalse();
    expect($policy->update($allowUser, $record))->toBeTrue();
    expect($policy->update($wrongTeamUser, $record))->toBeFalse();
    expect($policy->update($denyUser, $record))->toBeFalse();
    expect($policy->update(null, $record))->toBeFalse();

    $storeRes = $this->postJson('/api/v1/accounting/audit-support', [
        'reference' => 'AUD-2026-001',
        'title' => 'Bank Confirmations',
        'description' => 'Provide year-end bank confirmation letters.',
        'owner_id' => $user->id,
        'due_at' => '2026-03-31',
        'evidence' => [['file' => 'bank-letter.pdf']],
    ]);
    expect($storeRes->status())->toBeLessThan(300);
    $id = $storeRes->json('data.id');

    expect($this->getJson('/api/v1/accounting/audit-support')->status())->toBe(200);
    expect($this->getJson('/api/v1/accounting/audit-support?status=open&per_page=10')->status())->toBe(200);

    $submitRes = $this->postJson("/api/v1/accounting/audit-support/{$id}/submit");
    expect($submitRes->status())->toBe(200);
});
