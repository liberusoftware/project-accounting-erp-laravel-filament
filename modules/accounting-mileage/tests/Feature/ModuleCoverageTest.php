<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Liberu\Accounting\Mileage\Actions\ApproveTrip;
use Liberu\Accounting\Mileage\Actions\CaptureTrip;
use Liberu\Accounting\Mileage\Actions\CreateVehicle;
use Liberu\Accounting\Mileage\Actions\RecordMileageRate;
use Liberu\Accounting\Mileage\Actions\ReimburseTrip;
use Liberu\Accounting\Mileage\Actions\SubmitTrip;
use Liberu\Accounting\Mileage\Enums\ApprovalDecision;
use Liberu\Accounting\Mileage\Enums\TripStatus;
use Liberu\Accounting\Mileage\Events\TripApproved;
use Liberu\Accounting\Mileage\Events\TripCaptured;
use Liberu\Accounting\Mileage\Exceptions\InvalidMileage;
use Liberu\Accounting\Mileage\MileageServiceProvider;
use Liberu\Accounting\Mileage\Models\MileageApproval;
use Liberu\Accounting\Mileage\Models\MileagePolicy;
use Liberu\Accounting\Mileage\Models\MileageRate;
use Liberu\Accounting\Mileage\Models\MileageReimbursement;
use Liberu\Accounting\Mileage\Models\MileageTrip;
use Liberu\Accounting\Mileage\Models\Vehicle;
use Liberu\Accounting\Mileage\Queries\MileageQuery;

it('registers the service provider, singleton query, and migrations', function (): void {
    expect(app()->getProviders(MileageServiceProvider::class))->not->toBeEmpty()
        ->and(app(MileageQuery::class))->toBe(app(MileageQuery::class))
        ->and(Schema::hasTable('accounting_mileage_vehicles'))->toBeTrue()
        ->and(Schema::hasTable('accounting_mileage_rates'))->toBeTrue()
        ->and(Schema::hasTable('accounting_mileage_policies'))->toBeTrue()
        ->and(Schema::hasTable('accounting_mileage_trips'))->toBeTrue()
        ->and(Schema::hasTable('accounting_mileage_approvals'))->toBeTrue()
        ->and(Schema::hasTable('accounting_mileage_reimbursements'))->toBeTrue();
});

it('exposes mileage enums and exception', function (): void {
    expect(array_map(fn (ApprovalDecision $d) => $d->value, ApprovalDecision::cases()))
        ->toBe(['pending', 'approved', 'rejected'])
        ->and(array_map(fn (TripStatus $s) => $s->value, TripStatus::cases()))
        ->toBe(['draft', 'submitted', 'approved', 'rejected', 'reimbursed', 'cancelled']);

    $exception = new InvalidMileage('Bad mileage');
    expect($exception)->toBeInstanceOf(RuntimeException::class)
        ->and($exception->getMessage())->toBe('Bad mileage');
});

it('creates vehicle, rate, policy, captures trip idempotently, submits, approves/rejects, and reimburses', function (): void {
    $createVehicle = new CreateVehicle();
    $recordRate = new RecordMileageRate();
    $captureTrip = new CaptureTrip();
    $submitTrip = new SubmitTrip();
    $approveTrip = new ApproveTrip();
    $reimburseTrip = new ReimburseTrip();

    $vehicle = $createVehicle->handle([
        'team_id' => 12,
        'owner_ref' => 'emp-1',
        'registration' => ' ab12 cde ',
        'make' => 'Volvo',
        'model' => 'EX30',
        'fuel_type' => 'electric',
        'co2_g_per_km' => 0,
    ]);
    expect($vehicle)->toBeInstanceOf(Vehicle::class)
        ->and($vehicle->registration)->toBe('AB12 CDE')
        ->and($vehicle->active)->toBeTrue();

    $rate = $recordRate->handle([
        'team_id' => 12,
        'region' => 'UK',
        'vehicle_type' => 'car',
        'currency' => 'gbp',
        'rate_per_distance' => 0.45,
        'effective_from' => '2026-01-01',
    ]);
    expect($rate)->toBeInstanceOf(MileageRate::class)
        ->and($rate->currency)->toBe('GBP')
        ->and((float) $rate->rate_per_distance)->toBe(0.45);

    $policy = MileagePolicy::create([
        'team_id' => 12,
        'name' => 'UK Standard',
        'region' => 'UK',
        'max_distance_per_trip' => 500,
        'max_distance_per_day' => 800,
        'requires_purpose' => true,
        'requires_project' => false,
        'approval_threshold' => 100.00,
        'currency' => 'GBP',
        'active' => true,
    ]);
    expect($policy)->toBeInstanceOf(MileagePolicy::class)
        ->and($policy->requires_purpose)->toBeTrue()
        ->and($policy->requires_project)->toBeFalse();

    $tripAttributes = [
        'team_id' => 12,
        'trip_ref' => 'TRIP-001',
        'employee_ref' => 'emp-1',
        'vehicle_id' => $vehicle->id,
        'rate_id' => $rate->id,
        'policy_id' => $policy->id,
        'origin' => 'London',
        'destination' => 'Oxford',
        'trip_date' => '2026-02-10',
        'distance' => 100,
        'distance_unit' => 'miles',
        'business_purpose' => 'Client meeting',
        'region' => 'UK',
        'currency' => 'gbp',
        'rate_per_distance' => 0.45,
    ];

    $trip = $captureTrip->handle($tripAttributes);
    expect($trip)->toBeInstanceOf(MileageTrip::class)
        ->and($trip->status)->toBe(TripStatus::Draft)
        ->and((float) $trip->reimbursement_amount)->toBe(45.00)
        ->and($trip->currency)->toBe('GBP')
        ->and($trip->vehicle->is($vehicle))->toBeTrue()
        ->and($trip->rate->is($rate))->toBeTrue()
        ->and($trip->policy->is($policy))->toBeTrue()
        ->and($vehicle->trips()->count())->toBe(1);

    // Idempotent CaptureTrip
    expect($captureTrip->handle($tripAttributes)->id)->toBe($trip->id);

    $submitted = $submitTrip->handle($trip);
    expect($submitted->status)->toBe(TripStatus::Submitted)
        ->and($submitted->submitted_at)->not->toBeNull();

    $approved = $approveTrip->handle($submitted, 'mgr-1', approved: true);
    expect($approved->status)->toBe(TripStatus::Approved)
        ->and($approved->approved_at)->not->toBeNull()
        ->and($approved->approvals)->toHaveCount(1)
        ->and($approved->approvals->first())->toBeInstanceOf(MileageApproval::class)
        ->and($approved->approvals->first()->decision)->toBe(ApprovalDecision::Approved)
        ->and($approved->approvals->first()->trip->is($approved))->toBeTrue();

    $reimbursement = $reimburseTrip->handle($approved, 'emp-1', 'PAY-100');
    $approved->refresh();
    expect($reimbursement)->toBeInstanceOf(MileageReimbursement::class)
        ->and((float) $reimbursement->amount)->toBe(45.00)
        ->and($reimbursement->trip->is($approved))->toBeTrue()
        ->and($approved->reimbursements()->count())->toBe(1)
        ->and($approved->status)->toBe(TripStatus::Reimbursed)
        ->and($approved->reimbursed_at)->not->toBeNull();

    // Rejection path
    $trip2 = $captureTrip->handle([
        'team_id' => 12,
        'trip_ref' => 'TRIP-002',
        'employee_ref' => 'emp-2',
        'trip_date' => '2026-02-11',
        'distance' => 50,
        'business_purpose' => 'Site visit',
        'region' => 'US',
        'currency' => 'USD',
        'rate_per_distance' => 0.60,
    ]);
    $submitted2 = $submitTrip->handle($trip2);
    $rejected = $approveTrip->handle($submitted2, 'mgr-1', approved: false, reason: 'Personal travel');
    expect($rejected->status)->toBe(TripStatus::Rejected)
        ->and($rejected->approved_at)->toBeNull()
        ->and($rejected->approvals()->first()->decision)->toBe(ApprovalDecision::Rejected);

    // Events
    Event::fake([TripCaptured::class, TripApproved::class]);
    TripCaptured::dispatch($trip);
    TripApproved::dispatch($approved);
    Event::assertDispatched(TripCaptured::class);
    Event::assertDispatched(TripApproved::class);
});

it('throws InvalidMileage on all validation and state-transition errors', function (): void {
    $createVehicle = new CreateVehicle();
    $recordRate = new RecordMileageRate();
    $captureTrip = new CaptureTrip();
    $submitTrip = new SubmitTrip();
    $approveTrip = new ApproveTrip();
    $reimburseTrip = new ReimburseTrip();

    expect(fn () => $createVehicle->handle(['team_id' => null, 'registration' => '']))
        ->toThrow(InvalidMileage::class, 'A vehicle requires a tenant and registration.');

    $createVehicle->handle(['team_id' => 1, 'registration' => 'XYZ-99']);
    expect(fn () => $createVehicle->handle(['team_id' => 1, 'registration' => 'xyz-99']))
        ->toThrow(InvalidMileage::class, 'Vehicle registration already exists for this tenant.');

    expect(fn () => $recordRate->handle([
        'region' => 'UK',
        'vehicle_type' => 'car',
        'currency' => 'GBP',
        'rate_per_distance' => 0,
        'effective_from' => '2026-01-01',
    ]))->toThrow(InvalidMileage::class);

    expect(fn () => $captureTrip->handle([
        'trip_ref' => '',
        'employee_ref' => 'e1',
        'trip_date' => '2026-01-01',
        'distance' => 10,
        'region' => 'UK',
        'currency' => 'GBP',
    ]))->toThrow(InvalidMileage::class);

    $draftNoPurpose = $captureTrip->handle([
        'team_id' => 1,
        'trip_ref' => 'TRIP-NO-PURPOSE',
        'employee_ref' => 'e1',
        'trip_date' => '2026-01-01',
        'distance' => 10,
        'region' => 'UK',
        'currency' => 'GBP',
    ]);

    // Same trip_ref with different data throws
    expect(fn () => $captureTrip->handle([
        'team_id' => 1,
        'trip_ref' => 'TRIP-NO-PURPOSE',
        'employee_ref' => 'e1',
        'trip_date' => '2026-01-01',
        'distance' => 25,
        'region' => 'UK',
        'currency' => 'GBP',
    ]))->toThrow(InvalidMileage::class, 'Trip reference already exists with different source data.');

    // Submit requires business purpose
    expect(fn () => $submitTrip->handle($draftNoPurpose))
        ->toThrow(InvalidMileage::class, 'A business purpose is required before submission.');

    // Approve & Reimburse require Submitted / Approved respectively
    expect(fn () => $approveTrip->handle($draftNoPurpose, 'mgr'))
        ->toThrow(InvalidMileage::class, 'Only submitted trips can be approved or rejected.');
    expect(fn () => $reimburseTrip->handle($draftNoPurpose, 'e1'))
        ->toThrow(InvalidMileage::class, 'Only approved trips can be reimbursed.');

    $draft = $captureTrip->handle([
        'team_id' => 1,
        'trip_ref' => 'TRIP-ERR-FLOW',
        'employee_ref' => 'e1',
        'trip_date' => '2026-01-02',
        'distance' => 20,
        'business_purpose' => 'Audit',
        'region' => 'UK',
        'currency' => 'GBP',
        'rate_per_distance' => 0.45,
    ]);
    $submitted = $submitTrip->handle($draft);

    // Cannot submit non-draft trip
    expect(fn () => $submitTrip->handle($submitted))
        ->toThrow(InvalidMileage::class, 'Only draft trips can be submitted.');

    // Rejection without reason throws
    expect(fn () => $approveTrip->handle($submitted, 'mgr', approved: false, reason: ''))
        ->toThrow(InvalidMileage::class, 'A rejection requires a reason.');

    $approved = $approveTrip->handle($submitted, 'mgr', approved: true);
    $reimburseTrip->handle($approved, 'e1');

    // Duplicate reimbursement for same payee throws (needs fresh approved check bypassed or another approved trip? Wait: ReimburseTrip sets status to Reimbursed! Wait - check line 1017-1020!)
    // On an Approved trip that already has a reimbursement record for same payee:
    $approved->reimbursements()->create([
        'payee_ref' => 'dup-payee',
        'currency' => 'GBP',
        'amount' => 9.00,
        'status' => 'submitted',
    ]);
    $approved->update(['status' => TripStatus::Approved]);
    expect(fn () => $reimburseTrip->handle($approved->refresh(), 'dup-payee'))
        ->toThrow(InvalidMileage::class, 'This trip is already reimbursed for the payee.');
});

it('queries trips, rates, and regional report across empty and populated datasets', function (): void {
    $query = app(MileageQuery::class);
    expect($query->trips()->total())->toBe(0)
        ->and($query->rates()->total())->toBe(0)
        ->and($query->regionalReport())->toBe([
            'trip_count' => 0,
            'total_distance' => 0.0,
            'total_reimbursement' => 0.0,
            'by_region' => [],
        ]);

    $recordRate = new RecordMileageRate();
    $captureTrip = new CaptureTrip();
    $submitTrip = new SubmitTrip();
    $approveTrip = new ApproveTrip();
    $reimburseTrip = new ReimburseTrip();

    $recordRate->handle([
        'team_id' => 1,
        'region' => 'UK',
        'vehicle_type' => 'car',
        'currency' => 'GBP',
        'rate_per_distance' => 0.45,
        'effective_from' => '2026-01-01',
    ]);
    $recordRate->handle([
        'team_id' => 2,
        'region' => 'US',
        'vehicle_type' => 'car',
        'currency' => 'USD',
        'rate_per_distance' => 0.65,
        'effective_from' => '2026-01-01',
    ]);

    // Draft trip (should NOT be in regionalReport)
    $captureTrip->handle([
        'team_id' => 1,
        'trip_ref' => 'T-DRAFT',
        'employee_ref' => 'e1',
        'trip_date' => '2026-03-01',
        'distance' => 999,
        'business_purpose' => 'Pending',
        'region' => 'UK',
        'currency' => 'GBP',
        'rate_per_distance' => 0.45,
    ]);

    // Approved UK trip
    $tUk = $approveTrip->handle($submitTrip->handle($captureTrip->handle([
        'team_id' => 1,
        'trip_ref' => 'T-UK',
        'employee_ref' => 'e1',
        'trip_date' => '2026-03-02',
        'distance' => 100,
        'business_purpose' => 'Meeting',
        'region' => 'UK',
        'currency' => 'GBP',
        'rate_per_distance' => 0.45,
    ])), 'mgr');

    // Reimbursed US trip
    $tUs = $approveTrip->handle($submitTrip->handle($captureTrip->handle([
        'team_id' => 2,
        'trip_ref' => 'T-US',
        'employee_ref' => 'e2',
        'trip_date' => '2026-03-03',
        'distance' => 200,
        'business_purpose' => 'Conference',
        'region' => 'US',
        'currency' => 'USD',
        'rate_per_distance' => 0.50,
    ])), 'mgr');
    $reimburseTrip->handle($tUs, 'e2');

    expect($query->trips()->total())->toBe(3)
        ->and($query->trips(teamId: 1)->total())->toBe(2)
        ->and($query->trips(perPage: 0)->perPage())->toBe(1)
        ->and($query->rates()->total())->toBe(2)
        ->and($query->rates(teamId: 2)->total())->toBe(1)
        ->and($query->rates(perPage: 500)->perPage())->toBe(100);

    $allReport = $query->regionalReport();
    expect($allReport['trip_count'])->toBe(2)
        ->and($allReport['total_distance'])->toBe(300.0)
        ->and($allReport['total_reimbursement'])->toBe(145.0)
        ->and($allReport['by_region'])->toHaveCount(2);

    $ukReport = $query->regionalReport(teamId: 1, region: 'UK');
    expect($ukReport['trip_count'])->toBe(1)
        ->and($ukReport['total_distance'])->toBe(100.0)
        ->and($ukReport['total_reimbursement'])->toBe(45.0);
});
