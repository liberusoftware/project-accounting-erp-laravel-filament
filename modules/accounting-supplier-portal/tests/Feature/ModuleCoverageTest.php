<?php

declare(strict_types=1);

use Liberu\Accounting\SupplierPortal\Actions\AttachPortalDocument;
use Liberu\Accounting\SupplierPortal\Actions\CreatePortalResource;
use Liberu\Accounting\SupplierPortal\Actions\TransitionPortalResource;
use Liberu\Accounting\SupplierPortal\Enums\PortalResourceType;
use Liberu\Accounting\SupplierPortal\Enums\PortalStatus;
use Liberu\Accounting\SupplierPortal\Events\PortalDocumentAttached;
use Liberu\Accounting\SupplierPortal\Events\PortalResourceCreated;
use Liberu\Accounting\SupplierPortal\Events\PortalResourceStatusChanged;
use Liberu\Accounting\SupplierPortal\Exceptions\InvalidPortalResource;
use Liberu\Accounting\SupplierPortal\Models\PortalDocument;
use Liberu\Accounting\SupplierPortal\Models\PortalResource;
use Liberu\Accounting\SupplierPortal\Queries\PortalResourceQuery;
use Liberu\Accounting\SupplierPortal\SupplierPortalServiceProvider;

it('boots supplier portal service provider and covers enums, events, and exception', function (): void {
    $provider = $this->app->register(SupplierPortalServiceProvider::class, force: true);
    $provider->boot();

    expect($provider)->toBeInstanceOf(SupplierPortalServiceProvider::class)
        ->and(array_column(PortalResourceType::cases(), 'value'))->toBe([
            'profile_change',
            'purchase_order',
            'invoice',
            'statement',
            'dispute',
            'payment_status',
            'document',
        ])
        ->and(array_column(PortalStatus::cases(), 'value'))->toBe([
            'draft',
            'submitted',
            'under_review',
            'approved',
            'rejected',
            'paid',
            'disputed',
            'archived',
        ])
        ->and((new InvalidPortalResource('Portal error'))->getMessage())->toBe('Portal error');
});

it('creates portal resources, attaches documents, transitions statuses, and queries', function (): void {
    $create = app(CreatePortalResource::class);
    $attach = app(AttachPortalDocument::class);
    $transition = app(TransitionPortalResource::class);
    $query = app(PortalResourceQuery::class);

    $resource = $create->handle([
        'supplier_id' => 'SUP-100',
        'type' => PortalResourceType::Invoice,
        'reference' => 'INV-2026-001',
        'currency' => 'USD',
        'amount' => 1250.75,
        'payload' => ['po' => 'PO-1'],
        'metadata' => ['channel' => 'web'],
    ]);

    expect($resource)->toBeInstanceOf(PortalResource::class)
        ->and($resource->type)->toBe(PortalResourceType::Invoice)
        ->and($resource->status)->toBe(PortalStatus::Draft)
        ->and((float) $resource->amount)->toBe(1250.75)
        ->and($resource->payload)->toBe(['po' => 'PO-1'])
        ->and($resource->metadata)->toBe(['channel' => 'web'])
        ->and((new PortalResourceCreated($resource))->resource->is($resource))->toBeTrue();

    // String type path
    $second = $create->handle([
        'supplier_id' => 'SUP-200',
        'type' => 'statement',
        'reference' => 'STMT-2026-001',
        'currency' => 'EUR',
        'amount' => 0,
    ]);
    expect($second->type)->toBe(PortalResourceType::Statement);

    // Attach document and verify idempotency and hidden attributes
    $hash = hash('sha256', 'invoice-pdf-content');
    $doc = $attach->handle($resource, [
        'path' => 'portal/invoices/inv-001.pdf',
        'original_name' => 'inv-001.pdf',
        'mime_type' => 'application/pdf',
        'sha256' => $hash,
        'uploaded_by' => 42,
        'metadata' => ['pages' => 2],
    ]);

    $duplicateDoc = $attach->handle($resource, [
        'path' => 'portal/invoices/other.pdf',
        'original_name' => 'other.pdf',
        'mime_type' => 'application/pdf',
        'sha256' => $hash,
    ]);

    expect($doc)->toBeInstanceOf(PortalDocument::class)
        ->and($duplicateDoc->is($doc))->toBeTrue()
        ->and($doc->resource->is($resource))->toBeTrue()
        ->and($resource->documents()->count())->toBe(1)
        ->and($doc->toArray())->not->toHaveKeys(['path', 'sha256'])
        ->and((new PortalDocumentAttached($doc))->document->is($doc))->toBeTrue();

    // State transitions: Draft -> Submitted -> UnderReview -> Approved -> Disputed -> UnderReview -> Rejected -> Draft -> Archived
    $submitted = $transition->handle($resource, PortalStatus::Submitted);
    expect($submitted->status)->toBe(PortalStatus::Submitted)
        ->and($submitted->submitted_at)->not->toBeNull()
        ->and((new PortalResourceStatusChanged($submitted))->resource->is($submitted))->toBeTrue();

    $review = $transition->handle($submitted, 'under_review');
    expect($review->status)->toBe(PortalStatus::UnderReview);

    $approved = $transition->handle($review, PortalStatus::Approved);
    expect($approved->status)->toBe(PortalStatus::Approved)
        ->and($approved->approved_at)->not->toBeNull()
        ->and($approved->submitted_at)->not->toBeNull();

    $disputed = $transition->handle($approved, PortalStatus::Disputed);
    expect($disputed->status)->toBe(PortalStatus::Disputed);

    $reReview = $transition->handle($disputed, PortalStatus::UnderReview);
    $rejected = $transition->handle($reReview, PortalStatus::Rejected, 'Missing tax ID');
    expect($rejected->status)->toBe(PortalStatus::Rejected)
        ->and($rejected->rejected_reason)->toBe('Missing tax ID');

    $reDraft = $transition->handle($rejected, PortalStatus::Draft);
    expect($reDraft->status)->toBe(PortalStatus::Draft);

    $archived = $transition->handle($reDraft, PortalStatus::Archived);
    expect($archived->status)->toBe(PortalStatus::Archived);

    // Model scope & query filters + pagination clamping
    expect(PortalResource::query()->forSupplier('SUP-100')->count())->toBe(1);

    $all = $query->paginate(perPage: 200);
    expect($all->total())->toBe(2)
        ->and($all->perPage())->toBe(100);

    $filtered = $query->paginate('SUP-100', 'invoice', 'archived', 0);
    expect($filtered->total())->toBe(1)
        ->and($filtered->perPage())->toBe(1);
});

it('enforces validation and transition guards for portal resources', function (): void {
    $create = app(CreatePortalResource::class);
    $transition = app(TransitionPortalResource::class);

    $valid = [
        'supplier_id' => 'SUP-300',
        'type' => PortalResourceType::PurchaseOrder,
        'reference' => 'PO-300',
        'currency' => 'GBP',
        'amount' => 100,
    ];

    expect(fn () => $create->handle([...$valid, 'type' => null]))
        ->toThrow(InvalidPortalResource::class, 'A valid supplier portal resource type is required.')
        ->and(fn () => $create->handle([...$valid, 'type' => 'unknown_type']))
        ->toThrow(InvalidPortalResource::class, 'A valid supplier portal resource type is required.');

    foreach (['supplier_id', 'reference', 'currency'] as $required) {
        expect(fn () => $create->handle(array_diff_key($valid, [$required => true])))
            ->toThrow(InvalidPortalResource::class, "Portal field [{$required}] is required.");
    }

    expect(fn () => $create->handle([...$valid, 'amount' => -5]))
        ->toThrow(InvalidPortalResource::class, 'Portal amount must not be negative.');

    $resource = $create->handle($valid);

    expect(fn () => $create->handle($valid))
        ->toThrow(InvalidPortalResource::class, 'A resource with this supplier reference already exists.');

    expect(fn () => $transition->handle($resource, 'not_a_status'))
        ->toThrow(InvalidPortalResource::class, 'Unknown portal status.')
        ->and(fn () => $transition->handle($resource, PortalStatus::Approved))
        ->toThrow(InvalidPortalResource::class, 'Cannot transition draft to approved.');

    $submitted = $transition->handle($resource, PortalStatus::Submitted);
    expect(fn () => $transition->handle($submitted, PortalStatus::Rejected))
        ->toThrow(InvalidPortalResource::class, 'A rejection reason is required.');
});
