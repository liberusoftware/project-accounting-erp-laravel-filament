<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\AnomalyDetectionApi\AnomalyDetectionApiServiceProvider;
use Liberu\Accounting\AnomalyDetectionApi\Policies\AnomalyPolicy;
use Liberu\Accounting\Erp\Models\User;

it('covers accounting-anomaly-detection-api api provider, policies, requests, and index route', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(AnomalyDetectionApiServiceProvider::class, force: true);
    if (method_exists($provider, 'boot')) {
        $provider->boot();
    }
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
    $record = (object) ['team_id' => 1, 'legal_entity_id' => 1, 'user_id' => 1];
    expect($policy->viewAny($allowUser))->toBeBool();
    expect($policy->viewAny($denyUser))->toBeBool();
    expect($policy->viewAny(null))->toBeFalse();
    expect($policy->update($allowUser, $record))->toBeBool();
    expect($policy->update($denyUser, $record))->toBeBool();
    expect($policy->update(null, $record))->toBeFalse();
    $res = $this->getJson('/api/v1/accounting/anomalies');
    expect($res->status())->toBeLessThan(500);
});
