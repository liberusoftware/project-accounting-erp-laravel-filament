<?php

declare(strict_types=1);

use Liberu\Accounting\FinancialMasterData\Models\Party;
use Liberu\Accounting\SalesInvoicing\Actions\ApproveInvoice;
use Liberu\Accounting\SalesInvoicing\Actions\CreateInvoice;
use Liberu\Accounting\SalesInvoicing\Actions\FinalizeInvoice;
use Liberu\Accounting\SalesInvoicing\Actions\GenerateInvoicePdf;
use Liberu\Accounting\SalesInvoicing\Actions\MarkInvoiceDelivered;
use Liberu\Accounting\SalesInvoicing\Actions\RecordDeposit;
use Liberu\Accounting\SalesInvoicing\Actions\SetInvoiceBranding;
use Liberu\Accounting\SalesInvoicing\Actions\SetRecurringSource;
use Liberu\Accounting\SalesInvoicing\Enums\InvoiceStatus;
use Liberu\Accounting\SalesInvoicing\Events\InvoiceCreated;
use Liberu\Accounting\SalesInvoicing\Events\InvoiceFinalized;
use Liberu\Accounting\SalesInvoicing\Exceptions\InvalidInvoice;
use Liberu\Accounting\SalesInvoicing\Models\SalesInvoice;
use Liberu\Accounting\SalesInvoicing\Models\SalesInvoiceDeposit;
use Liberu\Accounting\SalesInvoicing\Models\SalesInvoiceLine;
use Liberu\Accounting\SalesInvoicing\SalesInvoicingServiceProvider;

it('boots sales invoicing service provider and covers enum, events, and exception', function (): void {
    $provider = $this->app->register(SalesInvoicingServiceProvider::class, force: true);
    $provider->boot();

    $invoice = new SalesInvoice(['invoice_number' => 'INV-EVT-1']);
    $createdEvent = new InvoiceCreated($invoice);
    $finalizedEvent = new InvoiceFinalized($invoice, 'finance@example.com');
    $finalizedDefaultEvent = new InvoiceFinalized($invoice);

    expect($provider)->toBeInstanceOf(SalesInvoicingServiceProvider::class)
        ->and(array_column(InvoiceStatus::cases(), 'value'))->toBe(['draft', 'approved', 'final'])
        ->and((new InvalidInvoice('Invoice error'))->getMessage())->toBe('Invoice error')
        ->and($createdEvent->invoice)->toBe($invoice)
        ->and($finalizedEvent->invoice)->toBe($invoice)
        ->and($finalizedEvent->actor)->toBe('finance@example.com')
        ->and($finalizedDefaultEvent->actor)->toBeNull();
});

it('executes full sales invoice lifecycle from draft creation through approval, finalization, pdf, delivery, and deposits', function (): void {
    $invoice = app(CreateInvoice::class)->handle([
        'invoice_number' => 'INV-2026-0001',
        'invoice_date' => '2026-08-24',
        'due_on' => '2026-09-23',
        'currency' => 'USD',
        'notes' => 'Initial invoice',
        'metadata' => ['origin' => 'erp'],
    ], [
        [
            'description' => 'Consulting Services',
            'quantity' => 2,
            'unit_price' => 100,
            'discount_rate' => 10,
            'tax_rate' => 20,
        ],
        [
            'description' => 'Support Retainer',
            'quantity' => 1,
            'unit_price' => 50,
            'discount_rate' => 0,
            'tax_rate' => 10,
        ],
    ]);

    expect($invoice)->toBeInstanceOf(SalesInvoice::class)
        ->and($invoice->status)->toBe(InvoiceStatus::Draft)
        ->and((float) $invoice->subtotal)->toBe(250.00)
        ->and((float) $invoice->discount_total)->toBe(20.00)
        ->and((float) $invoice->tax_total)->toBe(41.00)
        ->and((float) $invoice->total)->toBe(271.00)
        ->and($invoice->lines)->toHaveCount(2)
        ->and($invoice->party)->toBeNull()
        ->and($invoice->party()->getRelated())->toBeInstanceOf(Party::class)
        ->and($invoice->depositedTotal())->toBe(0.0)
        ->and($invoice->outstanding())->toBe(271.00);

    $firstLine = $invoice->lines->first();
    expect($firstLine)->toBeInstanceOf(SalesInvoiceLine::class)
        ->and((float) $firstLine->net_amount)->toBe(180.00)
        ->and((float) $firstLine->tax_amount)->toBe(36.00)
        ->and($firstLine->invoice->is($invoice))->toBeTrue();

    // Draft branding and recurring source updates
    $branded = app(SetInvoiceBranding::class)->handle($invoice, ['logo' => 'brand.png', 'color' => '#112233']);
    expect($branded->branding)->toBe(['logo' => 'brand.png', 'color' => '#112233']);

    $withRecurring = app(SetRecurringSource::class)->handle($branded, [
        'source_type' => 'subscription',
        'source_id' => 'sub_123',
    ]);
    expect($withRecurring->recurring_source)->toBe(['source_type' => 'subscription', 'source_id' => 'sub_123']);

    // Approve invoice
    $approved = app(ApproveInvoice::class)->handle($withRecurring);
    expect($approved->status)->toBe(InvoiceStatus::Approved);

    $approvedPdf = app(GenerateInvoicePdf::class)->handle($approved);
    expect($approvedPdf)->toBe([
        'invoice_id' => $approved->id,
        'format' => 'pdf',
        'filename' => 'invoice-INV-2026-0001.pdf',
        'immutable' => false,
    ]);

    // Finalize invoice
    $final = app(FinalizeInvoice::class)->handle($approved, 'cfo@example.com');
    expect($final->status)->toBe(InvoiceStatus::Final)
        ->and($final->lines)->toHaveCount(2);

    $finalPdf = app(GenerateInvoicePdf::class)->handle($final, 'pdf');
    expect($finalPdf['immutable'])->toBeTrue();

    // Direct delivery status update is allowed on final invoices by booted updating guard
    $final->update(['delivery_status' => 'queued']);
    expect($final->refresh()->delivery_status)->toBe('queued');

    // Mark delivered via action
    $delivered = app(MarkInvoiceDelivered::class)->handle($final);
    expect($delivered->delivery_status)->toBe('delivered')
        ->and($delivered->delivered_at)->not->toBeNull();

    // Record deposits up to total
    $afterFirstDeposit = app(RecordDeposit::class)->handle($delivered, [
        'amount' => 100.00,
        'reference' => 'DEP-1',
        'received_by' => 'cashier@example.com',
    ]);

    expect($afterFirstDeposit->deposits)->toHaveCount(1)
        ->and($afterFirstDeposit->depositedTotal())->toBe(100.0)
        ->and($afterFirstDeposit->outstanding())->toBe(171.0);

    $deposit = $afterFirstDeposit->deposits->first();
    expect($deposit)->toBeInstanceOf(SalesInvoiceDeposit::class)
        ->and($deposit->currency)->toBe('USD')
        ->and($deposit->invoice->is($delivered))->toBeTrue();

    $afterFullDeposit = app(RecordDeposit::class)->handle($afterFirstDeposit, [
        'amount' => 171.00,
        'reference' => 'DEP-2',
    ]);

    expect($afterFullDeposit->depositedTotal())->toBe(271.0)
        ->and($afterFullDeposit->outstanding())->toBe(0.0);
});

it('enforces validation, state transitions, immutability, and deletion guards across sales invoicing', function (): void {
    $create = app(CreateInvoice::class);
    $attrs = [
        'invoice_number' => 'INV-2026-0002',
        'invoice_date' => '2026-08-24',
        'currency' => 'EUR',
    ];

    expect(fn () => $create->handle($attrs, []))
        ->toThrow(InvalidInvoice::class, 'An invoice requires at least one line.')
        ->and(fn () => $create->handle($attrs, [['description' => '', 'quantity' => 1, 'unit_price' => 10]]))
        ->toThrow(InvalidInvoice::class, 'Invoice lines must have valid descriptions')
        ->and(fn () => $create->handle($attrs, [['description' => 'Item', 'quantity' => 0, 'unit_price' => 10]]))
        ->toThrow(InvalidInvoice::class, 'Invoice lines must have valid descriptions')
        ->and(fn () => $create->handle($attrs, [['description' => 'Item', 'quantity' => 1, 'unit_price' => -1]]))
        ->toThrow(InvalidInvoice::class, 'Invoice lines must have valid descriptions')
        ->and(fn () => $create->handle($attrs, [['description' => 'Item', 'quantity' => 1, 'unit_price' => 10, 'discount_rate' => -5]]))
        ->toThrow(InvalidInvoice::class, 'Invoice lines must have valid descriptions')
        ->and(fn () => $create->handle($attrs, [['description' => 'Item', 'quantity' => 1, 'unit_price' => 10, 'discount_rate' => 101]]))
        ->toThrow(InvalidInvoice::class, 'Invoice lines must have valid descriptions')
        ->and(fn () => $create->handle($attrs, [['description' => 'Item', 'quantity' => 1, 'unit_price' => 10, 'tax_rate' => -1]]))
        ->toThrow(InvalidInvoice::class, 'Invoice lines must have valid descriptions');

    $draft = $create->handle($attrs, [['description' => 'License', 'quantity' => 1, 'unit_price' => 100]]);

    // Draft guards on actions
    expect(fn () => app(FinalizeInvoice::class)->handle($draft))
        ->toThrow(InvalidInvoice::class, 'Only approved invoices may be finalized.')
        ->and(fn () => app(GenerateInvoicePdf::class)->handle($draft))
        ->toThrow(InvalidInvoice::class, 'Only approved or final invoices may generate a PDF.')
        ->and(fn () => app(MarkInvoiceDelivered::class)->handle($draft))
        ->toThrow(InvalidInvoice::class, 'Only final invoices may be delivered.')
        ->and(fn () => app(RecordDeposit::class)->handle($draft, ['amount' => 10]))
        ->toThrow(InvalidInvoice::class, 'Deposits require a final invoice')
        ->and(fn () => app(SetRecurringSource::class)->handle($draft, ['source_id' => '1']))
        ->toThrow(InvalidInvoice::class, 'Recurring source field [source_type] is required.')
        ->and(fn () => app(SetRecurringSource::class)->handle($draft, ['source_type' => 'plan']))
        ->toThrow(InvalidInvoice::class, 'Recurring source field [source_id] is required.');

    // Draft invoice can be deleted cleanly
    $deletableDraft = $create->handle(
        ['invoice_number' => 'INV-2026-DEL', 'invoice_date' => '2026-08-24', 'currency' => 'EUR'],
        [['description' => 'Temp', 'quantity' => 1, 'unit_price' => 10]]
    );
    expect($deletableDraft->delete())->toBeTrue();

    // Approved invoice guards
    $approved = app(ApproveInvoice::class)->handle($draft);

    expect(fn () => app(ApproveInvoice::class)->handle($approved))
        ->toThrow(InvalidInvoice::class, 'Only draft invoices may be approved.')
        ->and(fn () => app(SetInvoiceBranding::class)->handle($approved, ['logo' => 'x.png']))
        ->toThrow(InvalidInvoice::class, 'Branding can only be changed on a draft invoice.')
        ->and(fn () => app(SetRecurringSource::class)->handle($approved, ['source_type' => 'plan', 'source_id' => '1']))
        ->toThrow(InvalidInvoice::class, 'Recurring source can only be changed on a draft invoice.')
        ->and(fn () => app(GenerateInvoicePdf::class)->handle($approved, 'csv'))
        ->toThrow(InvalidInvoice::class, 'Only PDF output is supported by this boundary.')
        ->and(fn () => $approved->update(['notes' => 'Tamper']))
        ->toThrow(LogicException::class, 'Approved and final invoices are immutable.')
        ->and(fn () => $approved->delete())
        ->toThrow(LogicException::class, 'Approved and final invoices cannot be deleted.');

    // Final invoice guards
    $final = app(FinalizeInvoice::class)->handle($approved);

    expect(fn () => app(RecordDeposit::class)->handle($final, ['amount' => 0]))
        ->toThrow(InvalidInvoice::class, 'Deposits require a final invoice')
        ->and(fn () => app(RecordDeposit::class)->handle($final, ['amount' => 150]))
        ->toThrow(InvalidInvoice::class, 'Deposits require a final invoice')
        ->and(fn () => $final->update(['notes' => 'Tamper final']))
        ->toThrow(LogicException::class, 'Approved and final invoices are immutable.')
        ->and(fn () => $final->delete())
        ->toThrow(LogicException::class, 'Approved and final invoices cannot be deleted.');
});
