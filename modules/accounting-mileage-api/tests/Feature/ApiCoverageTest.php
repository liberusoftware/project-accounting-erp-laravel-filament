<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\MileageApi\MileageApiServiceProvider;

it('covers accounting-mileage-api provider, controller, and resource', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(MileageApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(MileageApiServiceProvider::class);

    $vehicleResponse = $this->postJson('/api/v1/accounting/mileage/vehicles', [
        'team_id' => 1,
        'registration' => 'AB12CDE',
        'owner_ref' => 'EMP-1',
        'make' => 'Ford',
        'model' => 'Focus',
        'fuel_type' => 'petrol',
    ]);
    $vehicleResponse->assertCreated();
    $vehicleId = $vehicleResponse->json('data.id');

    $this->getJson('/api/v1/accounting/mileage/vehicles?team_id=1')->assertOk();

    $rateResponse = $this->postJson('/api/v1/accounting/mileage/rates', [
        'team_id' => 1,
        'region' => 'UK',
        'vehicle_type' => 'car',
        'currency' => 'GBP',
        'rate_per_distance' => 0.45,
        'effective_from' => '2026-01-01',
    ]);
    $rateResponse->assertCreated();
    $rateId = $rateResponse->json('data.id');

    $tripResponse = $this->postJson('/api/v1/accounting/mileage', [
        'team_id' => 1,
        'trip_ref' => 'TRIP-001',
        'employee_ref' => 'EMP-1',
        'vehicle_id' => $vehicleId,
        'rate_id' => $rateId,
        'origin' => 'London',
        'destination' => 'Oxford',
        'trip_date' => '2026-02-01',
        'distance' => 60,
        'distance_unit' => 'mi',
        'business_purpose' => 'Client meeting',
        'region' => 'UK',
        'currency' => 'GBP',
        'rate_per_distance' => 0.45,
        'source' => 'manual',
    ]);
    $tripResponse->assertSuccessful();
    $tripId = $tripResponse->json('data.id');

    $this->getJson('/api/v1/accounting/mileage?team_id=1')->assertOk();
    $this->getJson("/api/v1/accounting/mileage/{$tripId}")->assertOk();
    $this->postJson("/api/v1/accounting/mileage/{$tripId}/submit")->assertOk();
    $this->postJson("/api/v1/accounting/mileage/{$tripId}/approve", [
        'approved' => true,
        'reason' => 'Valid business trip',
    ])->assertOk();
    $this->postJson("/api/v1/accounting/mileage/{$tripId}/reimburse", [
        'payee_ref' => 'EMP-1',
        'external_ref' => 'PAY-001',
    ])->assertCreated();
    $this->getJson('/api/v1/accounting/mileage/report/regional?team_id=1&region=UK')->assertOk();
});
