<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Liberu\Accounting\EInvoicing\Actions\ArchiveEInvoice;
use Liberu\Accounting\EInvoicing\Actions\CreateEInvoice;
use Liberu\Accounting\EInvoicing\Actions\ReconcileEInvoice;
use Liberu\Accounting\EInvoicing\Actions\RecordEInvoiceReceipt;
use Liberu\Accounting\EInvoicing\Actions\SignEInvoice;
use Liberu\Accounting\EInvoicing\Actions\SubmitEInvoice;
use Liberu\Accounting\EInvoicing\Actions\ValidateEInvoice;
use Liberu\Accounting\EInvoicing\EInvoicingServiceProvider;
use Liberu\Accounting\EInvoicing\Enums\DocumentStatus;
use Liberu\Accounting\EInvoicing\Events\EInvoiceStatusChanged;
use Liberu\Accounting\EInvoicing\Exceptions\InvalidEInvoice;
use Liberu\Accounting\EInvoicing\Models\EInvoiceDocument;
use Liberu\Accounting\EInvoicing\Models\EInvoiceEvent;
use Liberu\Accounting\EInvoicing\Queries\EInvoiceQuery;

it('boots e-invoicing service provider and covers enum, event, and exception', function (): void {
    $provider = $this->app->register(EInvoicingServiceProvider::class, force: true);
    $provider->boot();

    expect($provider)->toBeInstanceOf(EInvoicingServiceProvider::class)
        ->and(array_column(DocumentStatus::cases(), 'value'))->toBe([
            'draft',
            'validated',
            'signed',
            'submitted',
            'accepted',
            'rejected',
            'archived',
            'reconciled',
        ])
        ->and((new InvalidEInvoice('E-invoice error'))->getMessage())->toBe('E-invoice error');
});

it('executes full e-invoice lifecycle for accepted, reconciled, rejected, and archived documents plus queries', function (): void {
    Event::fake([EInvoiceStatusChanged::class]);

    $query = app(EInvoiceQuery::class);
    expect($query->paginate()->total())->toBe(0);

    $doc = app(CreateEInvoice::class)->handle([
        'legal_entity_id' => 10,
        'document_ref' => 'EINV-2026-001',
        'document_type' => 'invoice',
        'format' => 'ubl',
        'tax_id' => 'GB123456789',
        'counterparty_ref' => 'CUST-100',
        'currency' => 'eur',
        'payload' => [
            'invoice_number' => 'INV-001',
            'lines' => [['description' => 'Consulting', 'amount' => 1000]],
        ],
        'metadata' => ['channel' => 'peppol'],
        'actor_ref' => 'user:1',
    ]);

    expect($doc)->toBeInstanceOf(EInvoiceDocument::class)
        ->and($doc->status)->toBe(DocumentStatus::Draft)
        ->and($doc->currency)->toBe('EUR')
        ->and($doc->metadata)->toBe(['channel' => 'peppol'])
        ->and($doc->events()->count())->toBe(1);

    $firstEvent = $doc->events()->first();
    expect($firstEvent)->toBeInstanceOf(EInvoiceEvent::class)
        ->and($firstEvent->event)->toBe('created')
        ->and($firstEvent->actor_ref)->toBe('user:1')
        ->and($firstEvent->document->is($doc))->toBeTrue();

    $validated = app(ValidateEInvoice::class)->handle($doc, 'user:2');
    expect($validated->status)->toBe(DocumentStatus::Validated);

    $signed = app(SignEInvoice::class)->handle($validated, 'SIG-BASE64-XYZ', 'user:3');
    expect($signed->status)->toBe(DocumentStatus::Signed)
        ->and($signed->signature)->toBe('SIG-BASE64-XYZ');

    $submitted = app(SubmitEInvoice::class)->handle($signed, 'peppol-ap-1', 'user:4');
    expect($submitted->status)->toBe(DocumentStatus::Submitted)
        ->and($submitted->provider_ref)->toBe('peppol-ap-1')
        ->and($submitted->submitted_at)->not->toBeNull();

    $accepted = app(RecordEInvoiceReceipt::class)->handle($submitted, true, 'Accepted by network', 'provider:ap');
    expect($accepted->status)->toBe(DocumentStatus::Accepted)
        ->and($accepted->received_at)->not->toBeNull();

    $reconciled = app(ReconcileEInvoice::class)->handle($accepted, 'user:5');
    expect($reconciled->status)->toBe(DocumentStatus::Reconciled);

    $archived = app(ArchiveEInvoice::class)->handle($reconciled, 'user:6');
    expect($archived->status)->toBe(DocumentStatus::Archived)
        ->and($archived->archived_at)->not->toBeNull()
        ->and($archived->events()->count())->toBe(7);

    // Rejected flow -> Archive
    $doc2 = app(CreateEInvoice::class)->handle([
        'legal_entity_id' => 20,
        'document_ref' => 'EINV-2026-002',
        'document_type' => 'credit_note',
        'format' => 'factur-x',
        'tax_id' => 'DE999999999',
        'counterparty_ref' => 'CUST-200',
        'currency' => 'gbp',
        'payload' => [
            'invoice_number' => 'CN-002',
            'lines' => [['description' => 'Adjustment', 'amount' => -50]],
        ],
    ]);
    $doc2 = app(ValidateEInvoice::class)->handle($doc2);
    $doc2 = app(SignEInvoice::class)->handle($doc2, 'SIG-2');
    $doc2 = app(SubmitEInvoice::class)->handle($doc2, 'chorus-pro');
    $rejected = app(RecordEInvoiceReceipt::class)->handle($doc2, false, 'Invalid VAT number', 'provider:chorus');
    expect($rejected->status)->toBe(DocumentStatus::Rejected);

    $archivedRejected = app(ArchiveEInvoice::class)->handle($rejected, 'user:7');
    expect($archivedRejected->status)->toBe(DocumentStatus::Archived);

    Event::assertDispatched(EInvoiceStatusChanged::class, function (EInvoiceStatusChanged $e) use ($archived): bool {
        return $e->document->is($archived) && $e->event === 'archived' && $e->actorReference === 'user:6';
    });

    // Query filters and perPage clamping
    $allPage = $query->paginate(null, null, 200);
    expect($allPage->total())->toBe(2)
        ->and($allPage->perPage())->toBe(100);

    $filteredPage = $query->paginate(10, DocumentStatus::Archived, 0);
    expect($filteredPage->total())->toBe(1)
        ->and($filteredPage->perPage())->toBe(1)
        ->and($filteredPage->items()[0]->is($archived))->toBeTrue();
});

it('enforces validation and state transitions across e-invoicing actions', function (): void {
    $create = app(CreateEInvoice::class);
    $valid = [
        'legal_entity_id' => 1,
        'document_ref' => 'DOC-ERR-1',
        'document_type' => 'invoice',
        'format' => 'peppol',
        'tax_id' => 'US12345',
        'counterparty_ref' => 'CP-1',
        'currency' => 'USD',
        'payload' => [
            'invoice_number' => 'INV-ERR-1',
            'lines' => [['amount' => 10]],
        ],
    ];

    foreach (['legal_entity_id', 'document_ref', 'document_type', 'format', 'tax_id', 'counterparty_ref', 'currency', 'payload'] as $required) {
        expect(fn () => $create->handle(array_diff_key($valid, [$required => true])))
            ->toThrow(InvalidEInvoice::class, "Missing document field [{$required}].");
    }

    expect(fn () => $create->handle([...$valid, 'format' => 'xml']))
        ->toThrow(InvalidEInvoice::class, 'structured format, currency, or payload is invalid')
        ->and(fn () => $create->handle([...$valid, 'currency' => 'US']))
        ->toThrow(InvalidEInvoice::class, 'structured format, currency, or payload is invalid')
        ->and(fn () => $create->handle([...$valid, 'payload' => 'raw-string']))
        ->toThrow(InvalidEInvoice::class, 'structured format, currency, or payload is invalid');

    // Draft with invalid payload fails validation
    $badPayloadDoc = $create->handle([
        ...$valid,
        'document_ref' => 'DOC-BAD-PAYLOAD',
        'payload' => ['invoice_number' => 'INV-NO-LINES', 'lines' => 'not-an-array'],
    ]);
    expect(fn () => app(ValidateEInvoice::class)->handle($badPayloadDoc))
        ->toThrow(InvalidEInvoice::class, 'require an invoice number and line items');

    // Invalid state transitions from Draft
    $draft = $create->handle($valid);
    expect(fn () => app(SignEInvoice::class)->handle($draft, 'SIG'))->toThrow(InvalidEInvoice::class, 'Only validated documents')
        ->and(fn () => app(SubmitEInvoice::class)->handle($draft, 'prov'))->toThrow(InvalidEInvoice::class, 'Only signed documents')
        ->and(fn () => app(RecordEInvoiceReceipt::class)->handle($draft, true))->toThrow(InvalidEInvoice::class, 'Only submitted documents')
        ->and(fn () => app(ReconcileEInvoice::class)->handle($draft))->toThrow(InvalidEInvoice::class, 'Only accepted documents')
        ->and(fn () => app(ArchiveEInvoice::class)->handle($draft))->toThrow(InvalidEInvoice::class, 'Only completed documents');

    $validated = app(ValidateEInvoice::class)->handle($draft);
    expect(fn () => app(ValidateEInvoice::class)->handle($validated))->toThrow(InvalidEInvoice::class, 'Only draft documents')
        ->and(fn () => app(SignEInvoice::class)->handle($validated, '   '))->toThrow(InvalidEInvoice::class, 'Only validated documents');

    $signed = app(SignEInvoice::class)->handle($validated, 'SIG-OK');
    expect(fn () => app(SubmitEInvoice::class)->handle($signed, ''))->toThrow(InvalidEInvoice::class, 'Only signed documents');

    $submitted = app(SubmitEInvoice::class)->handle($signed, 'peppol-ap');
    expect(fn () => app(RecordEInvoiceReceipt::class)->handle($submitted, false, ''))
        ->toThrow(InvalidEInvoice::class, 'rejection receipt requires a message');
});
