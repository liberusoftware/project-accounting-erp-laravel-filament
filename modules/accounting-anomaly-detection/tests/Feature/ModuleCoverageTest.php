<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Event;
use Liberu\Accounting\AnomalyDetection\Actions\RecordAnomaly;
use Liberu\Accounting\AnomalyDetection\Actions\SendToReview;
use Liberu\Accounting\AnomalyDetection\AnomalyDetectionServiceProvider;
use Liberu\Accounting\AnomalyDetection\Enums\AnomalyStatus;
use Liberu\Accounting\AnomalyDetection\Models\Anomaly;
use Liberu\Accounting\AnomalyDetection\Queries\AnomalyQuery;

it('boots the service provider and covers enum, model, actions, and query', function (): void {
    Event::fake();

    $provider = $this->app->register(AnomalyDetectionServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(AnomalyDetectionServiceProvider::class);

    expect(array_map(fn (AnomalyStatus $s): string => $s->value, AnomalyStatus::cases()))
        ->toBe(['open', 'dismissed', 'sent_to_review', 'confirmed']);

    $record = app(RecordAnomaly::class);
    $sendToReview = app(SendToReview::class);
    $query = app(AnomalyQuery::class);

    expect($query->paginate(42)->total())->toBe(0);

    // Validation branches
    expect(fn () => $record->handle(['kind' => 'duplicate', 'title' => 'Dup']))
        ->toThrow(InvalidArgumentException::class, 'team_id is required.');
    expect(fn () => $record->handle(['team_id' => 1, 'title' => 'Dup']))
        ->toThrow(InvalidArgumentException::class, 'kind is required.');
    expect(fn () => $record->handle(['team_id' => 1, 'kind' => 'duplicate', 'title' => '']))
        ->toThrow(InvalidArgumentException::class, 'title is required.');

    // Happy path with default detected_at
    $older = $record->handle([
        'team_id' => 1,
        'kind' => 'outlier',
        'source_type' => 'JournalEntry',
        'source_id' => '100',
        'title' => 'Large Manual Journal',
        'description' => 'Exceeds 3 sigma',
        'confidence' => 0.9125,
        'evidence' => ['z_score' => 3.4],
        'detected_at' => CarbonImmutable::parse('2026-04-01 10:00:00'),
    ]);

    $newer = $record->handle([
        'team_id' => 1,
        'kind' => 'duplicate_invoice',
        'title' => 'Duplicate Vendor Invoice',
    ]);

    $record->handle([
        'team_id' => 2,
        'kind' => 'outlier',
        'title' => 'Other Team',
    ]);

    expect($older)->toBeInstanceOf(Anomaly::class)
        ->and($older->status)->toBe(AnomalyStatus::Open)
        ->and((string) $older->confidence)->toBe('0.9125')
        ->and($older->evidence)->toBe(['z_score' => 3.4])
        ->and($older->detected_at)->toBeInstanceOf(CarbonInterface::class)
        ->and($older->resolved_at)->toBeNull()
        ->and($newer->detected_at)->not->toBeNull();

    // SendToReview happy path
    $reviewed = $sendToReview->handle($older);
    expect($reviewed->status)->toBe(AnomalyStatus::SentToReview)
        ->and($reviewed->resolved_at)->not->toBeNull();

    // SendToReview failure when not open
    expect(fn () => $sendToReview->handle($reviewed->fresh()))
        ->toThrow(InvalidArgumentException::class, 'Only open anomalies may be sent to review.');

    // Query filtering, ordering, and perPage clamping
    $page = $query->paginate(1);
    expect($page->total())->toBe(2)
        ->and($page->items()[0]->id)->toBe($newer->id);

    $filtered = $query->paginate(1, AnomalyStatus::SentToReview->value);
    expect($filtered->total())->toBe(1)
        ->and($filtered->items()[0]->id)->toBe($older->id);

    expect($query->paginate(1, null, 0)->perPage())->toBe(1)
        ->and($query->paginate(1, null, 300)->perPage())->toBe(100);
});
