<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Liberu\Accounting\Leases\Actions\CreateDisclosure;
use Liberu\Accounting\Leases\Actions\CreateLease;
use Liberu\Accounting\Leases\Actions\GenerateSchedule;
use Liberu\Accounting\Leases\Actions\ModifyLease;
use Liberu\Accounting\Leases\Actions\PostPayment;
use Liberu\Accounting\Leases\Enums\LeaseStatus;
use Liberu\Accounting\Leases\Enums\PaymentStatus;
use Liberu\Accounting\Leases\Events\LeaseActivated;
use Liberu\Accounting\Leases\Exceptions\InvalidLease;
use Liberu\Accounting\Leases\LeasesServiceProvider;
use Liberu\Accounting\Leases\Models\Lease;
use Liberu\Accounting\Leases\Models\LeaseDisclosure;
use Liberu\Accounting\Leases\Models\LeaseModification;
use Liberu\Accounting\Leases\Models\LeasePayment;
use Liberu\Accounting\Leases\Queries\LeaseQuery;

it('registers the service provider, singleton query, and migrations', function (): void {
    expect(app()->getProviders(LeasesServiceProvider::class))->not->toBeEmpty()
        ->and(app(LeaseQuery::class))->toBe(app(LeaseQuery::class))
        ->and(Schema::hasTable('accounting_leases'))->toBeTrue()
        ->and(Schema::hasTable('accounting_lease_payments'))->toBeTrue()
        ->and(Schema::hasTable('accounting_lease_modifications'))->toBeTrue()
        ->and(Schema::hasTable('accounting_lease_disclosures'))->toBeTrue();
});

it('exposes all lease and payment enum cases and exception', function (): void {
    expect(array_map(fn (LeaseStatus $s) => $s->value, LeaseStatus::cases()))
        ->toBe(['draft', 'active', 'modified', 'completed', 'terminated'])
        ->and(array_map(fn (PaymentStatus $s) => $s->value, PaymentStatus::cases()))
        ->toBe(['scheduled', 'posted', 'skipped', 'failed']);

    $exception = new InvalidLease('Bad lease');
    expect($exception)->toBeInstanceOf(RuntimeException::class)
        ->and($exception->getMessage())->toBe('Bad lease');
});

it('creates a lease, generates discounted and zero-rate schedules, posts payments, modifies, and creates disclosures', function (): void {
    $createLease = new CreateLease();
    $generateSchedule = new GenerateSchedule();
    $postPayment = new PostPayment();
    $modifyLease = new ModifyLease();
    $createDisclosure = new CreateDisclosure();

    $lease = $createLease->handle([
        'team_id' => 10,
        'lease_ref' => 'LSE-001',
        'lessor_ref' => 'LESSOR-1',
        'asset_ref' => 'ASSET-1',
        'commencement_date' => '2026-01-01',
        'end_date' => '2027-07-01',
        'currency' => 'gbp',
        'payment_amount' => 1000.00,
        'interest_rate' => 0.06,
        'discount_rate' => 0.06,
        'useful_life_months' => 18,
        'metadata' => ['department' => 'HQ'],
    ]);

    expect($lease)->toBeInstanceOf(Lease::class)
        ->and($lease->name)->toBe('LSE-001')
        ->and($lease->currency)->toBe('GBP')
        ->and($lease->payment_frequency)->toBe('monthly')
        ->and($lease->status)->toBe(LeaseStatus::Draft)
        ->and($lease->metadata)->toBe(['department' => 'HQ']);

    $activated = $generateSchedule->handle($lease);

    expect($activated->status)->toBe(LeaseStatus::Active)
        ->and($activated->payments)->toHaveCount(18)
        ->and((float) $activated->lease_liability)->toBeGreaterThan(0)
        ->and((float) $activated->right_of_use_asset)->toBe((float) $activated->lease_liability)
        ->and((float) $activated->accumulated_depreciation)->toBe(0.0);

    $firstPayment = $activated->payments->first();
    expect($firstPayment)->toBeInstanceOf(LeasePayment::class)
        ->and($firstPayment->status)->toBe(PaymentStatus::Scheduled)
        ->and($firstPayment->lease->is($activated))->toBeTrue();

    $posted = $postPayment->handle($firstPayment);
    $activated->refresh();

    expect($posted->status)->toBe(PaymentStatus::Posted)
        ->and($posted->posted_at)->not->toBeNull()
        ->and((float) $activated->lease_liability)->toBeLessThan((float) $activated->right_of_use_asset)
        ->and((float) $activated->accumulated_depreciation)->toBeGreaterThan(0);

    // Disclosure with non-current liability > 0
    $disclosure = $createDisclosure->handle($activated, '2026-02-01');
    expect($disclosure)->toBeInstanceOf(LeaseDisclosure::class)
        ->and($disclosure->lease->is($activated))->toBeTrue()
        ->and((float) $disclosure->current_liability)->toBe(12000.00)
        ->and((float) $disclosure->non_current_liability)->toBeGreaterThan(0)
        ->and($disclosure->future_payments)->toHaveCount(17);

    // Modify active lease
    $modified = $modifyLease->handle($activated, [
        'modification_ref' => 'MOD-001',
        'effective_date' => '2026-03-01',
        'kind' => 'extension',
        'new_term_end' => '2028-01-01',
        'new_payment_amount' => 1100.00,
        'adjustment_amount' => 500.00,
        'reason' => 'Extended term',
    ]);

    expect($modified->status)->toBe(LeaseStatus::Modified)
        ->and($modified->end_date->toDateString())->toBe('2028-01-01')
        ->and((float) $modified->payment_amount)->toBe(1100.00)
        ->and($modified->modifications)->toHaveCount(1)
        ->and($modified->modifications->first())->toBeInstanceOf(LeaseModification::class)
        ->and($modified->modifications->first()->lease->is($modified))->toBeTrue();

    // Regenerate schedule on Modified lease (replaces old payments)
    $regenerated = $generateSchedule->handle($modified);
    expect($regenerated->status)->toBe(LeaseStatus::Active)
        ->and($regenerated->payments)->toHaveCount(24);

    // Zero discount rate lease with same start and end date (months clamped to 1)
    $shortLease = $createLease->handle([
        'team_id' => 11,
        'lease_ref' => 'LSE-002',
        'name' => 'Short Lease',
        'lessor_ref' => 'LESSOR-2',
        'commencement_date' => '2026-05-01',
        'end_date' => '2026-05-01',
        'currency' => 'usd',
        'payment_amount' => 500.00,
        'discount_rate' => 0,
        'useful_life_months' => 1,
    ]);
    $shortActive = $generateSchedule->handle($shortLease);
    expect($shortActive->payments)->toHaveCount(1)
        ->and((float) $shortActive->lease_liability)->toBe(500.00);

    // Disclosure updateOrCreate and zero non_current_liability
    $shortDisclosure = $createDisclosure->handle($shortActive, '2026-05-01');
    expect((float) $shortDisclosure->current_liability)->toBe(500.00)
        ->and((float) $shortDisclosure->non_current_liability)->toBe(0.0);

    $updatedDisclosure = $createDisclosure->handle($shortActive, '2026-05-01');
    expect($updatedDisclosure->id)->toBe($shortDisclosure->id)
        ->and($shortActive->disclosures()->count())->toBe(1);
});

it('validates lease creation, schedule generation, payment posting, and modification errors', function (): void {
    $createLease = new CreateLease();
    $generateSchedule = new GenerateSchedule();
    $postPayment = new PostPayment();
    $modifyLease = new ModifyLease();

    expect(fn () => $createLease->handle([
        'lease_ref' => '  ',
        'lessor_ref' => 'L1',
        'commencement_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'currency' => 'USD',
        'payment_amount' => 100,
        'useful_life_months' => 12,
    ]))->toThrow(InvalidLease::class, 'A lease requires lessor, dates, currency, positive payment, and useful life.');

    expect(fn () => $createLease->handle([
        'lease_ref' => 'LSE-BAD-DATE',
        'lessor_ref' => 'L1',
        'commencement_date' => '2026-06-01',
        'end_date' => '2026-01-01',
        'currency' => 'USD',
        'payment_amount' => 100,
        'useful_life_months' => 12,
    ]))->toThrow(InvalidLease::class, 'Lease end date must not precede commencement.');

    $lease = $createLease->handle([
        'lease_ref' => 'LSE-ERR',
        'lessor_ref' => 'L1',
        'commencement_date' => '2026-01-01',
        'end_date' => '2026-03-01',
        'currency' => 'EUR',
        'payment_amount' => 200,
        'discount_rate' => 0.05,
        'useful_life_months' => 2,
    ]);

    // Cannot modify a draft lease
    expect(fn () => $modifyLease->handle($lease, [
        'modification_ref' => 'MOD-1',
        'effective_date' => '2026-02-01',
    ]))->toThrow(InvalidLease::class, 'Only active leases can be modified.');

    $active = $generateSchedule->handle($lease);

    // Cannot regenerate schedule on an active lease
    expect(fn () => $generateSchedule->handle($active))
        ->toThrow(InvalidLease::class, 'Only draft or modified leases can have a schedule generated.');

    // Modification requires ref and effective_date
    expect(fn () => $modifyLease->handle($active, [
        'modification_ref' => '',
        'effective_date' => '2026-02-01',
    ]))->toThrow(InvalidLease::class, 'A modification requires reference and effective date.');

    $payment = $active->payments->first();
    $postPayment->handle($payment);

    // Cannot post an already posted payment
    expect(fn () => $postPayment->handle($payment->refresh()))
        ->toThrow(InvalidLease::class, 'Only scheduled lease payments can be posted.');
});

it('queries leases with filtering, pagination clamping, and disclosure summary, and dispatches events', function (): void {
    $query = app(LeaseQuery::class);

    // Empty state
    $empty = $query->leases();
    expect($empty->total())->toBe(0);

    $createLease = new CreateLease();
    $generateSchedule = new GenerateSchedule();

    $draft = $createLease->handle([
        'team_id' => 1,
        'lease_ref' => 'L-1',
        'lessor_ref' => 'A',
        'commencement_date' => '2026-01-01',
        'end_date' => '2026-04-01',
        'currency' => 'USD',
        'payment_amount' => 300,
        'useful_life_months' => 3,
    ]);
    $active = $generateSchedule->handle($createLease->handle([
        'team_id' => 2,
        'lease_ref' => 'L-2',
        'lessor_ref' => 'B',
        'commencement_date' => '2026-01-01',
        'end_date' => '2026-04-01',
        'currency' => 'EUR',
        'payment_amount' => 400,
        'useful_life_months' => 3,
    ]));

    expect($query->leases()->total())->toBe(2)
        ->and($query->leases(teamId: 1)->total())->toBe(1)
        ->and($query->leases(status: 'active')->total())->toBe(1)
        ->and($query->leases(perPage: 0)->perPage())->toBe(1)
        ->and($query->leases(perPage: 500)->perPage())->toBe(100);

    $summary = $query->disclosure($active);
    expect($summary)->toMatchArray([
        'lease_ref' => 'L-2',
        'currency' => 'EUR',
        'scheduled_payments' => 3,
    ])->and($summary['liability'])->toBe(1200.0)
        ->and($summary['right_of_use_asset'])->toBe(1200.0)
        ->and($summary['accumulated_depreciation'])->toBe(0.0);

    // Event & listener test
    Event::fake([LeaseActivated::class]);
    $received = null;
    Event::listen(LeaseActivated::class, function (LeaseActivated $event) use (&$received): void {
        $received = $event;
    });
    LeaseActivated::dispatch($draft);
    Event::assertDispatched(LeaseActivated::class, fn (LeaseActivated $e) => $e->lease->is($draft));
});
