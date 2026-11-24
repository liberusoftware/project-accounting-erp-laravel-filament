<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Liberu\Accounting\DocumentCapture\Actions\ArchiveDocument;
use Liberu\Accounting\DocumentCapture\Actions\ExtractDocument;
use Liberu\Accounting\DocumentCapture\Actions\MarkDuplicateDocument;
use Liberu\Accounting\DocumentCapture\Actions\ReviewDocument;
use Liberu\Accounting\DocumentCapture\Actions\UploadDocument;
use Liberu\Accounting\DocumentCapture\DocumentCaptureServiceProvider;
use Liberu\Accounting\DocumentCapture\Enums\CaptureStatus;
use Liberu\Accounting\DocumentCapture\Events\CaptureStatusChanged;
use Liberu\Accounting\DocumentCapture\Exceptions\InvalidCapture;
use Liberu\Accounting\DocumentCapture\Models\CapturedDocument;
use Liberu\Accounting\DocumentCapture\Models\CaptureEvent;
use Liberu\Accounting\DocumentCapture\Queries\CaptureQuery;

it('registers and boots the document capture service provider and migrations', function (): void {
    $provider = app()->getProvider(DocumentCaptureServiceProvider::class)
        ?? new DocumentCaptureServiceProvider(app());

    $provider->register();
    $provider->boot();

    expect($provider)->toBeInstanceOf(DocumentCaptureServiceProvider::class)
        ->and(app(CaptureQuery::class))->toBe(app(CaptureQuery::class))
        ->and(Schema::hasTable('accounting_captured_documents'))->toBeTrue()
        ->and(Schema::hasTable('accounting_capture_events'))->toBeTrue();
});

it('exposes capture status enum, exception, and event contract', function (): void {
    expect(array_map(static fn (CaptureStatus $s): string => $s->value, CaptureStatus::cases()))
        ->toBe(['uploaded', 'extracted', 'in_review', 'approved', 'rejected', 'archived']);

    $exception = new InvalidCapture('Capture failed.');
    expect($exception)->toBeInstanceOf(DomainException::class)
        ->and($exception->getMessage())->toBe('Capture failed.');

    $doc = app(UploadDocument::class)->handle([
        'team_id' => 1,
        'source_channel' => 'web',
        'file_ref' => 's3://bucket/inv-1.pdf',
        'checksum' => 'sha256-contract-1',
        'mime_type' => 'application/pdf',
    ]);

    $event = new CaptureStatusChanged($doc, 'extracted', 'actor-1');
    expect($event)->toBeInstanceOf(ShouldDispatchAfterCommit::class)
        ->and($event->document->is($doc))->toBeTrue()
        ->and($event->event)->toBe('extracted')
        ->and($event->actorReference)->toBe('actor-1');
});

it('uploads documents and validates required fields, channel, mime type, and duplicates', function (): void {
    $upload = app(UploadDocument::class);

    foreach (['source_channel', 'file_ref', 'checksum', 'mime_type'] as $field) {
        $payload = [
            'team_id' => 10,
            'source_channel' => 'email',
            'file_ref' => 's3://docs/a.pdf',
            'checksum' => 'chk-1',
            'mime_type' => 'application/pdf',
        ];
        $payload[$field] = '';

        expect(fn () => $upload->handle($payload))
            ->toThrow(InvalidCapture::class, "Missing source field [{$field}].");
    }

    expect(fn () => $upload->handle([
        'team_id' => 10,
        'source_channel' => 'ftp',
        'file_ref' => 's3://docs/a.pdf',
        'checksum' => 'chk-ftp',
        'mime_type' => 'application/pdf',
    ]))->toThrow(InvalidCapture::class, 'Source channel or MIME type is unsupported.');

    expect(fn () => $upload->handle([
        'team_id' => 10,
        'source_channel' => 'mobile',
        'file_ref' => 's3://docs/a.txt',
        'checksum' => 'chk-txt',
        'mime_type' => 'text/plain',
    ]))->toThrow(InvalidCapture::class, 'Source channel or MIME type is unsupported.');

    $doc = $upload->handle([
        'team_id' => 10,
        'source_channel' => 'mobile',
        'file_ref' => 's3://docs/receipt.png',
        'checksum' => 'chk-dup',
        'mime_type' => 'image/png',
        'retention_until' => '2030-12-31',
        'metadata' => ['device' => 'ios'],
        'actor_ref' => 'user-42',
    ]);

    expect($doc)->toBeInstanceOf(CapturedDocument::class)
        ->and($doc->team_id)->toBe(10)
        ->and($doc->source_channel)->toBe('mobile')
        ->and($doc->status)->toBe(CaptureStatus::Uploaded)
        ->and($doc->retention_until?->toDateString())->toBe('2030-12-31')
        ->and($doc->metadata)->toBe(['device' => 'ios'])
        ->and($doc->events)->toHaveCount(1);

    $firstEvent = $doc->events->first();
    expect($firstEvent)->toBeInstanceOf(CaptureEvent::class)
        ->and($firstEvent->event)->toBe('uploaded')
        ->and($firstEvent->actor_ref)->toBe('user-42')
        ->and($firstEvent->document->is($doc))->toBeTrue();

    // Duplicate checksum in same team throws.
    expect(fn () => $upload->handle([
        'team_id' => 10,
        'source_channel' => 'web',
        'file_ref' => 's3://docs/receipt-2.png',
        'checksum' => 'chk-dup',
        'mime_type' => 'image/png',
    ]))->toThrow(InvalidCapture::class, 'The source document is a duplicate.');

    // Same checksum in a different team is allowed.
    $otherTeamDoc = $upload->handle([
        'team_id' => 11,
        'source_channel' => 'email',
        'file_ref' => 's3://docs/receipt-other.png',
        'checksum' => 'chk-dup',
        'mime_type' => 'image/png',
    ]);
    expect($otherTeamDoc->team_id)->toBe(11);
});

it('extracts, reviews (approve & reject), and archives captured documents with events', function (): void {
    Carbon::setTestNow('2026-08-29 14:30:00');
    Event::fake([CaptureStatusChanged::class]);

    $upload = app(UploadDocument::class);
    $extract = app(ExtractDocument::class);
    $review = app(ReviewDocument::class);
    $archive = app(ArchiveDocument::class);

    $doc = $upload->handle([
        'team_id' => 20,
        'source_channel' => 'web',
        'file_ref' => 's3://docs/bill-1.pdf',
        'checksum' => 'chk-lifecycle-1',
        'mime_type' => 'application/pdf',
    ]);

    // Review and archive before extraction throw.
    expect(fn () => $review->handle($doc, true, null, 'reviewer-1'))
        ->toThrow(InvalidCapture::class, 'Only extracted documents can be reviewed.');
    expect(fn () => $archive->handle($doc, 'archiver-1'))
        ->toThrow(InvalidCapture::class, 'Only reviewed documents can be archived.');

    // Invalid extraction inputs throw.
    expect(fn () => $extract->handle($doc, ['lines' => [['desc' => 'Item']]], -0.1, 'ocr-v1'))
        ->toThrow(InvalidCapture::class, 'Extraction confidence, adapter, or line data is invalid.');
    expect(fn () => $extract->handle($doc, ['lines' => [['desc' => 'Item']]], 1.1, 'ocr-v1'))
        ->toThrow(InvalidCapture::class, 'Extraction confidence, adapter, or line data is invalid.');
    expect(fn () => $extract->handle($doc, ['lines' => [['desc' => 'Item']]], 0.9, ' '))
        ->toThrow(InvalidCapture::class, 'Extraction confidence, adapter, or line data is invalid.');
    expect(fn () => $extract->handle($doc, ['lines' => []], 0.9, 'ocr-v1'))
        ->toThrow(InvalidCapture::class, 'Extraction confidence, adapter, or line data is invalid.');
    expect(fn () => $extract->handle($doc, ['total' => 100], 0.9, 'ocr-v1'))
        ->toThrow(InvalidCapture::class, 'Extraction confidence, adapter, or line data is invalid.');

    // Valid extraction.
    $extracted = $extract->handle($doc, ['lines' => [['description' => 'Hosting', 'amount' => 99.0]]], 0.9542, 'ocr-v2', 'bot-1');
    expect($extracted->status)->toBe(CaptureStatus::Extracted)
        ->and($extracted->confidence)->toBe(0.9542)
        ->and($extracted->extracted_data)->toBe(['lines' => [['description' => 'Hosting', 'amount' => 99.0]]]);

    // Re-extracting an already extracted document throws.
    expect(fn () => $extract->handle($extracted, ['lines' => [['amount' => 1]]], 0.8, 'ocr-v2'))
        ->toThrow(InvalidCapture::class, 'Only uploaded documents can be extracted.');

    // Rejecting without reason throws.
    expect(fn () => $review->handle($extracted, false, '  ', 'reviewer-1'))
        ->toThrow(InvalidCapture::class, 'Rejected capture requires a reason.');

    // Approving succeeds.
    $approved = $review->handle($extracted, true, null, 'reviewer-1');
    expect($approved->status)->toBe(CaptureStatus::Approved)
        ->and($approved->reviewed_by)->toBe('reviewer-1')
        ->and($approved->reviewed_at?->toDateTimeString())->toBe('2026-08-29 14:30:00')
        ->and($approved->rejection_reason)->toBeNull();

    // Archiving approved document succeeds.
    $archived = $archive->handle($approved, 'archiver-1');
    expect($archived->status)->toBe(CaptureStatus::Archived)
        ->and($archived->events()->count())->toBe(4);

    // Reject & archive path.
    $doc2 = $upload->handle([
        'team_id' => 20,
        'source_channel' => 'email',
        'file_ref' => 's3://docs/bill-2.pdf',
        'checksum' => 'chk-lifecycle-2',
        'mime_type' => 'application/pdf',
    ]);
    $doc2 = $extract->handle($doc2, ['lines' => [['description' => 'Blur', 'amount' => 10.0]]], 0.4, 'ocr-v2');
    $rejected = $review->handle($doc2, false, 'Unreadable scan', 'reviewer-2');
    expect($rejected->status)->toBe(CaptureStatus::Rejected)
        ->and($rejected->rejection_reason)->toBe('Unreadable scan');

    $archivedRejected = $archive->handle($rejected, 'archiver-2');
    expect($archivedRejected->status)->toBe(CaptureStatus::Archived);

    Event::assertDispatched(CaptureStatusChanged::class, 6);
    Event::assertDispatched(CaptureStatusChanged::class, static fn (CaptureStatusChanged $e): bool => $e->event === 'extracted' && $e->actorReference === 'bot-1');
    Event::assertDispatched(CaptureStatusChanged::class, static fn (CaptureStatusChanged $e): bool => $e->event === 'approved' && $e->actorReference === 'reviewer-1');
    Event::assertDispatched(CaptureStatusChanged::class, static fn (CaptureStatusChanged $e): bool => $e->event === 'rejected' && $e->actorReference === 'reviewer-2');
    Event::assertDispatched(CaptureStatusChanged::class, static fn (CaptureStatusChanged $e): bool => $e->event === 'archived' && $e->actorReference === 'archiver-1');

    Carbon::setTestNow();
});

it('marks duplicate documents and enforces team and active-original guards', function (): void {
    $upload = app(UploadDocument::class);
    $extract = app(ExtractDocument::class);
    $review = app(ReviewDocument::class);
    $archive = app(ArchiveDocument::class);
    $markDuplicate = app(MarkDuplicateDocument::class);

    $original = $upload->handle([
        'team_id' => 30,
        'source_channel' => 'web',
        'file_ref' => 's3://docs/orig.pdf',
        'checksum' => 'chk-orig',
        'mime_type' => 'application/pdf',
    ]);
    $dup = $upload->handle([
        'team_id' => 30,
        'source_channel' => 'web',
        'file_ref' => 's3://docs/copy.pdf',
        'checksum' => 'chk-copy',
        'mime_type' => 'application/pdf',
    ]);
    $otherTeam = $upload->handle([
        'team_id' => 31,
        'source_channel' => 'web',
        'file_ref' => 's3://docs/other.pdf',
        'checksum' => 'chk-other',
        'mime_type' => 'application/pdf',
    ]);

    // Self-reference throws.
    expect(fn () => $markDuplicate->handle($dup, $dup))
        ->toThrow(InvalidCapture::class, 'A duplicate must reference another active document in the same team.');

    // Cross-team reference throws.
    expect(fn () => $markDuplicate->handle($dup, $otherTeam))
        ->toThrow(InvalidCapture::class, 'A duplicate must reference another active document in the same team.');

    // Archived original throws.
    $archivedOriginal = $upload->handle([
        'team_id' => 30,
        'source_channel' => 'web',
        'file_ref' => 's3://docs/old.pdf',
        'checksum' => 'chk-old',
        'mime_type' => 'application/pdf',
    ]);
    $archivedOriginal = $extract->handle($archivedOriginal, ['lines' => [['amount' => 1]]], 1.0, 'ocr');
    $archivedOriginal = $review->handle($archivedOriginal, true, null, 'rev');
    $archivedOriginal = $archive->handle($archivedOriginal, 'arch');

    expect(fn () => $markDuplicate->handle($dup, $archivedOriginal))
        ->toThrow(InvalidCapture::class, 'A duplicate must reference another active document in the same team.');

    // Valid duplicate marking.
    $marked = $markDuplicate->handle($dup, $original, 'auditor-1');
    expect($marked->duplicate_of)->toBe($original->id)
        ->and($marked->status)->toBe(CaptureStatus::Rejected)
        ->and($marked->rejection_reason)->toBe('Duplicate source document');

    $dupEvent = $marked->events()->where('event', 'duplicate_marked')->first();
    expect($dupEvent)->not->toBeNull()
        ->and($dupEvent->actor_ref)->toBe('auditor-1')
        ->and($dupEvent->metadata)->toBe(['original_id' => $original->id]);
});

it('paginates captured documents by team and status with clamped perPage', function (): void {
    $query = app(CaptureQuery::class);
    $upload = app(UploadDocument::class);
    $extract = app(ExtractDocument::class);

    expect($query->paginate()->total())->toBe(0);

    $d1 = $upload->handle([
        'team_id' => 40,
        'source_channel' => 'web',
        'file_ref' => 's3://docs/q1.pdf',
        'checksum' => 'chk-q1',
        'mime_type' => 'application/pdf',
    ]);
    $d2 = $upload->handle([
        'team_id' => 40,
        'source_channel' => 'mobile',
        'file_ref' => 's3://docs/q2.jpg',
        'checksum' => 'chk-q2',
        'mime_type' => 'image/jpeg',
    ]);
    $extract->handle($d2, ['lines' => [['amount' => 5]]], 0.85, 'ocr');

    $upload->handle([
        'team_id' => 41,
        'source_channel' => 'email',
        'file_ref' => 's3://docs/q3.pdf',
        'checksum' => 'chk-q3',
        'mime_type' => 'application/pdf',
    ]);

    $all = $query->paginate();
    expect($all->total())->toBe(3)
        ->and($all->items()[0]->relationLoaded('events'))->toBeTrue();

    $team40 = $query->paginate(teamId: 40);
    expect($team40->total())->toBe(2);

    $team40Extracted = $query->paginate(teamId: 40, status: CaptureStatus::Extracted);
    expect($team40Extracted->total())->toBe(1)
        ->and($team40Extracted->items()[0]->id)->toBe($d2->id);

    $clampedMin = $query->paginate(perPage: 0);
    expect($clampedMin->perPage())->toBe(1);

    $clampedMax = $query->paginate(perPage: 500);
    expect($clampedMax->perPage())->toBe(100);
});
