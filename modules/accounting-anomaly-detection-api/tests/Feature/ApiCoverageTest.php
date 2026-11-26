<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\AnomalyDetection\Enums\AnomalyStatus;
use Liberu\Accounting\AnomalyDetection\Models\Anomaly;
use Liberu\Accounting\AnomalyDetectionApi\AnomalyDetectionApiServiceProvider;
use Liberu\Accounting\AnomalyDetectionApi\Policies\AnomalyPolicy;
use Liberu\Accounting\Erp\Models\User;

it('covers accounting-anomaly-detection-api provider, policy, controller, and resource', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(AnomalyDetectionApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(AnomalyDetectionApiServiceProvider::class);

    $policy = new AnomalyPolicy();
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
    expect($policy->update($allowUser, $record))->toBeTrue();
    expect($policy->update($wrongTeamUser, $record))->toBeFalse();
    expect($policy->update($denyUser, $record))->toBeFalse();
    expect($policy->update(null, $record))->toBeFalse();

    $anomaly = Anomaly::create([
        'team_id' => 1,
        'kind' => 'duplicate_invoice',
        'source_type' => 'invoice',
        'source_id' => 'INV-99',
        'title' => 'Possible duplicate invoice',
        'description' => 'Matching amount and vendor within 24h',
        'confidence' => 0.9250,
        'status' => AnomalyStatus::Open,
        'evidence' => ['matched_id' => 'INV-98'],
        'detected_at' => now(),
    ]);

    expect($this->getJson('/api/v1/accounting/anomalies')->status())->toBe(200);
    expect($this->getJson('/api/v1/accounting/anomalies?status=open&per_page=10')->status())->toBe(200);

    $reviewRes = $this->postJson("/api/v1/accounting/anomalies/{$anomaly->id}/send-to-review");
    expect($reviewRes->status())->toBe(200);
});
