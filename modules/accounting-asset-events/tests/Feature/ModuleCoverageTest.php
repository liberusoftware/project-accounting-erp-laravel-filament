<?php

declare(strict_types=1);

use Carbon\CarbonInterface;
use Liberu\Accounting\AssetEvents\Actions\RecordAssetEvent;
use Liberu\Accounting\AssetEvents\AssetEventsServiceProvider;
use Liberu\Accounting\AssetEvents\Models\AssetEvent;
use Liberu\Accounting\AssetEvents\Queries\AssetEventQuery;

it('boots the service provider and covers immutable model, record action, and query', function (): void {
    $provider = $this->app->register(AssetEventsServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(AssetEventsServiceProvider::class);

    $record = app(RecordAssetEvent::class);
    $query = app(AssetEventQuery::class);

    expect($query->paginate(1)->total())->toBe(0);

    // Validation branches
    foreach (['team_id', 'asset_id', 'event_type', 'event_date'] as $missingField) {
        $payload = [
            'team_id' => 1,
            'asset_id' => 55,
            'event_type' => 'acquired',
            'event_date' => '2026-03-01',
        ];
        unset($payload[$missingField]);
        expect(fn () => $record->handle($payload))
            ->toThrow(InvalidArgumentException::class, "{$missingField} is required.");
    }

    // Happy path
    $first = $record->handle([
        'team_id' => 1,
        'asset_id' => 55,
        'event_type' => 'acquired',
        'event_date' => '2026-03-01',
        'description' => 'Laptop purchased',
        'source_type' => 'VendorBill',
        'source_id' => '99',
        'old_values' => ['cost' => 0],
        'new_values' => ['cost' => 2500],
        'evidence' => ['invoice' => 'INV-99'],
        'actor_id' => '7',
    ]);

    $second = $record->handle([
        'team_id' => 1,
        'asset_id' => 55,
        'event_type' => 'depreciated',
        'event_date' => '2026-03-31',
    ]);

    $otherAsset = $record->handle([
        'team_id' => 1,
        'asset_id' => 56,
        'event_type' => 'acquired',
        'event_date' => '2026-04-01',
    ]);

    $record->handle([
        'team_id' => 2,
        'asset_id' => 55,
        'event_type' => 'acquired',
        'event_date' => '2026-04-02',
    ]);

    expect($first)->toBeInstanceOf(AssetEvent::class)
        ->and($first->event_date)->toBeInstanceOf(CarbonInterface::class)
        ->and($first->old_values)->toBe(['cost' => 0])
        ->and($first->new_values)->toBe(['cost' => 2500])
        ->and($first->evidence)->toBe(['invoice' => 'INV-99'])
        ->and($first->actor_id)->toBe(7);

    // Immutability guards
    expect(fn () => $first->update(['description' => 'Tampered']))
        ->toThrow(LogicException::class, 'Asset events are immutable.');
    expect(fn () => $first->delete())
        ->toThrow(LogicException::class, 'Asset events are immutable.');

    // Query filtering, ordering, and perPage clamping
    $allTeam1 = $query->paginate(1);
    expect($allTeam1->total())->toBe(3)
        ->and($allTeam1->items()[0]->id)->toBe($otherAsset->id);

    $forAsset55 = $query->paginate(1, 55);
    expect($forAsset55->total())->toBe(2)
        ->and($forAsset55->items()[0]->id)->toBe($second->id)
        ->and($forAsset55->items()[1]->id)->toBe($first->id);

    expect($query->paginate(1, null, 0)->perPage())->toBe(1)
        ->and($query->paginate(1, null, 500)->perPage())->toBe(100);
});
