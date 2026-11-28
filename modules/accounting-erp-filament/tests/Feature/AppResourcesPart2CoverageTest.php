<?php

declare(strict_types=1);

use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Liberu\Accounting\Erp\Models\Currency;
use Liberu\Accounting\Erp\Models\JournalEntry;
use Liberu\Accounting\Erp\Models\Payment;
use Liberu\Accounting\Erp\Models\PurchaseRequest;
use Liberu\Accounting\Erp\Models\RefundReceipt;
use Liberu\Accounting\Erp\Models\SalesReceipt;
use Liberu\Accounting\Erp\Models\Subscription;
use Liberu\Accounting\Erp\Models\TaxForm;
use Liberu\Accounting\Erp\Models\TaxRate;
use Liberu\Accounting\Erp\Models\VendorCredit;
use Liberu\Accounting\Erp\Services\ExchangeRateService;
use Liberu\Accounting\Erp\Services\PaymentPostingService;
use Liberu\Accounting\Erp\Services\PostingReversalService;
use Liberu\Accounting\Erp\Services\ProcurementService;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\Payments\Pages\CreatePayment;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\Payments\Pages\EditPayment;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\Payments\Pages\ListPayments;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\Payments\PaymentResource;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\PaymentTerms\Pages\CreatePaymentTerm;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\PaymentTerms\Pages\EditPaymentTerm;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\PaymentTerms\Pages\ListPaymentTerms;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\PaymentTerms\PaymentTermResource;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\Plans\Pages\CreatePlan;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\Plans\Pages\EditPlan;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\Plans\Pages\ListPlans;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\Plans\PlanResource;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\PurchaseRequests\Pages\CreatePurchaseRequest;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\PurchaseRequests\Pages\EditPurchaseRequest;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\PurchaseRequests\Pages\ListPurchaseRequests;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\PurchaseRequests\PurchaseRequestResource;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\ReconciliationRules\Pages\CreateReconciliationRule;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\ReconciliationRules\Pages\EditReconciliationRule;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\ReconciliationRules\Pages\ListReconciliationRules;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\ReconciliationRules\ReconciliationRuleResource;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\RefundReceipts\Pages\CreateRefundReceipt;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\RefundReceipts\Pages\EditRefundReceipt;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\RefundReceipts\Pages\ListRefundReceipts;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\RefundReceipts\RefundReceiptResource;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\ReminderSettings\Pages\CreateReminderSetting;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\ReminderSettings\Pages\EditReminderSetting;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\ReminderSettings\Pages\ListReminderSettings;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\ReminderSettings\ReminderSettingResource;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\SalesReceipts\Pages\CreateSalesReceipt;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\SalesReceipts\Pages\EditSalesReceipt;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\SalesReceipts\Pages\ListSalesReceipts;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\SalesReceipts\SalesReceiptResource;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\Subscriptions\Pages\CreateSubscription;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\Subscriptions\Pages\EditSubscription;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\Subscriptions\Pages\ListSubscriptions;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\Subscriptions\SubscriptionResource;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\Suppliers\Pages\CreateSupplier;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\Suppliers\Pages\EditSupplier;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\Suppliers\Pages\ListSuppliers;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\Suppliers\SupplierResource;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\TaxForms\Pages\CreateTaxForm;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\TaxForms\Pages\EditTaxForm;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\TaxForms\Pages\ListTaxForms;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\TaxForms\TaxFormResource;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\TaxRates\Pages\CreateTaxRate;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\TaxRates\Pages\EditTaxRate;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\TaxRates\Pages\ListTaxRates;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\TaxRates\TaxRateResource;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\Transactions\Pages\CreateTransaction;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\Transactions\Pages\EditTransaction;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\Transactions\Pages\ListTransactions;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\Transactions\TransactionResource;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\VendorCredits\Pages\CreateVendorCredit;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\VendorCredits\Pages\EditVendorCredit;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\VendorCredits\Pages\ListVendorCredits;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\VendorCredits\VendorCreditResource;

beforeEach(function (): void {
    Http::fake();
    Mail::fake();
    Queue::fake();
    Event::fake();
    Storage::fake();
    NotificationFacade::fake();
});

$findSchemaComponent = static function (Schema $schema, string $name): mixed {
    foreach ($schema->getFlatComponents(withHidden: true) as $component) {
        if (method_exists($component, 'getName') && $component->getName() === $name) {
            return $component;
        }
    }

    return null;
};

$readProperty = static function (object $target, string $property): mixed {
    return Closure::bind(fn (): mixed => $this->{$property}, $target, $target::class)();
};

$invokeProtected = static function (object $target, string $method, mixed ...$args): mixed {
    return Closure::bind(fn (): mixed => $this->{$method}(...$args), $target, $target::class)();
};

it('covers PaymentResource and its pages including ledger posting and reversal actions', function () use ($readProperty, $invokeProtected): void {
    $listPage = new ListPayments();
    $createPage = new CreatePayment();
    $editPage = new EditPayment();

    expect($listPage::getResource())->toBe(PaymentResource::class)
        ->and($createPage::getResource())->toBe(PaymentResource::class)
        ->and($editPage::getResource())->toBe(PaymentResource::class)
        ->and($invokeProtected($listPage, 'getHeaderActions'))->toBeArray()->toHaveCount(1)
        ->and($invokeProtected($editPage, 'getHeaderActions'))->toBeArray()->toHaveCount(1)
        ->and(PaymentResource::getRelations())->toBeArray()
        ->and(PaymentResource::getPages())->toHaveKeys(['index', 'create', 'edit']);

    $schema = PaymentResource::form(Schema::make($listPage));
    $table = PaymentResource::table(Table::make($listPage));

    expect($schema)->toBeInstanceOf(Schema::class)
        ->and($table)->toBeInstanceOf(Table::class);

    $postAction = $table->getAction('postToLedger');
    $unpostAction = $table->getAction('unpost');

    expect($postAction)->not->toBeNull()
        ->and($unpostAction)->not->toBeNull();

    $unpostedPayment = new Payment();
    $unpostedPayment->journal_entry_id = null;

    $postedPayment = new Payment();
    $postedPayment->journal_entry_id = 55;

    $postVisible = $readProperty($postAction, 'isVisible');
    $unpostVisible = $readProperty($unpostAction, 'isVisible');

    expect($postVisible($unpostedPayment))->toBeTrue()
        ->and($postVisible($postedPayment))->toBeFalse()
        ->and($unpostVisible($unpostedPayment))->toBeFalse()
        ->and($unpostVisible($postedPayment))->toBeTrue();

    $journalEntry = new JournalEntry();
    $journalEntry->id = 99;

    $postingService = Mockery::mock(PaymentPostingService::class);
    $postingService->shouldReceive('post')->once()->with($unpostedPayment)->andReturn($journalEntry);
    $postingService->shouldReceive('post')->once()->with($postedPayment)->andThrow(new RuntimeException('Already posted'));
    $this->app->instance(PaymentPostingService::class, $postingService);

    $postFn = $postAction->getActionFunction();
    $postFn($unpostedPayment);
    $postFn($postedPayment);

    $reversalService = Mockery::mock(PostingReversalService::class);
    $reversalService->shouldReceive('reversePayment')->once()->with($postedPayment);
    $reversalService->shouldReceive('reversePayment')->once()->with($unpostedPayment)->andThrow(new RuntimeException('Not posted'));
    $this->app->instance(PostingReversalService::class, $reversalService);

    $unpostFn = $unpostAction->getActionFunction();
    $unpostFn($postedPayment);
    $unpostFn($unpostedPayment);
});

it('covers PaymentTermResource and its pages', function () use ($invokeProtected): void {
    $listPage = new ListPaymentTerms();
    $createPage = new CreatePaymentTerm();
    $editPage = new EditPaymentTerm();

    expect($listPage::getResource())->toBe(PaymentTermResource::class)
        ->and($createPage::getResource())->toBe(PaymentTermResource::class)
        ->and($editPage::getResource())->toBe(PaymentTermResource::class)
        ->and($invokeProtected($listPage, 'getHeaderActions'))->toBeArray()->toHaveCount(1)
        ->and($invokeProtected($editPage, 'getHeaderActions'))->toBeArray()->toHaveCount(1)
        ->and(PaymentTermResource::getRelations())->toBeArray()
        ->and(PaymentTermResource::getPages())->toHaveKeys(['index', 'create', 'edit'])
        ->and(PaymentTermResource::form(Schema::make($listPage)))->toBeInstanceOf(Schema::class)
        ->and(PaymentTermResource::table(Table::make($listPage)))->toBeInstanceOf(Table::class);
});

it('covers PlanResource and its pages', function () use ($invokeProtected): void {
    $listPage = new ListPlans();
    $createPage = new CreatePlan();
    $editPage = new EditPlan();

    expect($listPage::getResource())->toBe(PlanResource::class)
        ->and($createPage::getResource())->toBe(PlanResource::class)
        ->and($editPage::getResource())->toBe(PlanResource::class)
        ->and($invokeProtected($listPage, 'getHeaderActions'))->toBeArray()->toHaveCount(1)
        ->and($invokeProtected($editPage, 'getHeaderActions'))->toBeArray()->toHaveCount(1)
        ->and(PlanResource::getRelations())->toBeArray()
        ->and(PlanResource::getPages())->toHaveKeys(['index', 'create', 'edit'])
        ->and(PlanResource::form(Schema::make($listPage)))->toBeInstanceOf(Schema::class)
        ->and(PlanResource::table(Table::make($listPage)))->toBeInstanceOf(Table::class);
});

it('covers PurchaseRequestResource and its pages including approval and conversion actions', function () use ($readProperty, $invokeProtected): void {
    $listPage = new ListPurchaseRequests();
    $createPage = new CreatePurchaseRequest();
    $editPage = new EditPurchaseRequest();

    expect($listPage::getResource())->toBe(PurchaseRequestResource::class)
        ->and($createPage::getResource())->toBe(PurchaseRequestResource::class)
        ->and($editPage::getResource())->toBe(PurchaseRequestResource::class)
        ->and($invokeProtected($listPage, 'getHeaderActions'))->toBeArray()->toHaveCount(1)
        ->and($invokeProtected($editPage, 'getHeaderActions'))->toBeArray()->toHaveCount(1)
        ->and(PurchaseRequestResource::getPages())->toHaveKeys(['index', 'create', 'edit']);

    $schema = PurchaseRequestResource::form(Schema::make($listPage));
    $table = PurchaseRequestResource::table(Table::make($listPage));

    expect($schema)->toBeInstanceOf(Schema::class)
        ->and($table)->toBeInstanceOf(Table::class);

    $statusColumn = $table->getColumn('approval_status');
    expect($statusColumn)->toBeInstanceOf(TextColumn::class)
        ->and($statusColumn->getColor('approved'))->toBe('success')
        ->and($statusColumn->getColor('rejected'))->toBe('danger')
        ->and($statusColumn->getColor('pending'))->toBe('warning');

    $submitAction = $table->getAction('submitForApproval');
    $convertAction = $table->getAction('convertToPurchaseOrder');

    expect($submitAction)->not->toBeNull()
        ->and($convertAction)->not->toBeNull();

    $pendingRequest = Mockery::mock(PurchaseRequest::class)->makePartial();
    $pendingRequest->forceFill(['approval_status' => 'pending']);
    $pendingRequest->shouldReceive('submitForApproval')->once();

    $failingRequest = Mockery::mock(PurchaseRequest::class)->makePartial();
    $failingRequest->forceFill(['approval_status' => 'pending']);
    $failingRequest->shouldReceive('submitForApproval')->once()->andThrow(new RuntimeException('Submit failed'));

    $hasOneMissing = Mockery::mock(HasOne::class);
    $hasOneMissing->shouldReceive('exists')->once()->andReturn(false);

    $approvedRequest = Mockery::mock(PurchaseRequest::class)->makePartial();
    $approvedRequest->forceFill(['approval_status' => 'approved']);
    $approvedRequest->shouldReceive('purchaseOrder')->once()->andReturn($hasOneMissing);

    $submitVisible = $readProperty($submitAction, 'isVisible');
    $convertVisible = $readProperty($convertAction, 'isVisible');

    expect($submitVisible($pendingRequest))->toBeTrue()
        ->and($submitVisible($approvedRequest))->toBeFalse()
        ->and($convertVisible($pendingRequest))->toBeFalse()
        ->and($convertVisible($approvedRequest))->toBeTrue();

    $submitFn = $submitAction->getActionFunction();
    $submitFn($pendingRequest);
    $submitFn($failingRequest);

    $procurement = Mockery::mock(ProcurementService::class);
    $procurement->shouldReceive('createPurchaseOrderFromRequest')->once()->with($approvedRequest);
    $procurement->shouldReceive('createPurchaseOrderFromRequest')->once()->with($pendingRequest)->andThrow(new DomainException('Not approved'));
    $procurement->shouldReceive('createPurchaseOrderFromRequest')->once()->with($failingRequest)->andThrow(new RuntimeException('DB error'));
    $this->app->instance(ProcurementService::class, $procurement);

    $convertFn = $convertAction->getActionFunction();
    $convertFn($approvedRequest);
    $convertFn($pendingRequest);
    $convertFn($failingRequest);
});

it('covers ReconciliationRuleResource and its pages', function () use ($invokeProtected): void {
    $listPage = new ListReconciliationRules();
    $createPage = new CreateReconciliationRule();
    $editPage = new EditReconciliationRule();

    expect($listPage::getResource())->toBe(ReconciliationRuleResource::class)
        ->and($createPage::getResource())->toBe(ReconciliationRuleResource::class)
        ->and($editPage::getResource())->toBe(ReconciliationRuleResource::class)
        ->and($invokeProtected($listPage, 'getHeaderActions'))->toBeArray()->toHaveCount(1)
        ->and($invokeProtected($editPage, 'getHeaderActions'))->toBeArray()->toHaveCount(1)
        ->and(ReconciliationRuleResource::getPages())->toHaveKeys(['index', 'create', 'edit'])
        ->and(ReconciliationRuleResource::form(Schema::make($listPage)))->toBeInstanceOf(Schema::class)
        ->and(ReconciliationRuleResource::table(Table::make($listPage)))->toBeInstanceOf(Table::class);
});

it('covers RefundReceiptResource and its pages including lifecycle hooks and actions', function () use ($findSchemaComponent, $readProperty, $invokeProtected): void {
    $listPage = new ListRefundReceipts();
    $createPage = new CreateRefundReceipt();
    $editPage = new EditRefundReceipt();

    expect($listPage::getResource())->toBe(RefundReceiptResource::class)
        ->and($createPage::getResource())->toBe(RefundReceiptResource::class)
        ->and($editPage::getResource())->toBe(RefundReceiptResource::class)
        ->and(RefundReceiptResource::getRelations())->toBeArray()
        ->and(RefundReceiptResource::getPages())->toHaveKeys(['index', 'create', 'edit']);

    $schema = RefundReceiptResource::form(Schema::make($listPage)->model(RefundReceipt::class));
    $table = RefundReceiptResource::table(Table::make($listPage));

    $receiptNumber = $findSchemaComponent($schema, 'refund_receipt_number');
    $visibleFn = $readProperty($receiptNumber, 'isVisible');
    expect($visibleFn(null))->toBeFalse()
        ->and($visibleFn(new RefundReceipt()))->toBeTrue();

    $itemsRepeater = $findSchemaComponent($schema, 'items');
    $itemLabelFn = $readProperty($itemsRepeater, 'itemLabel');
    expect($itemLabelFn(['description' => 'Returned Widget']))->toBe('Returned Widget')
        ->and($itemLabelFn([]))->toBeNull();

    $methodColumn = $table->getColumn('payment_method');
    expect($methodColumn)->toBeInstanceOf(TextColumn::class)
        ->and($methodColumn->formatState('credit_card'))->toBe('Credit Card');

    $processAction = $table->getAction('process');
    $voidAction = $table->getAction('void');

    $draftReceipt = Mockery::mock(RefundReceipt::class)->makePartial();
    $draftReceipt->forceFill(['status' => 'draft']);
    $draftReceipt->shouldReceive('process')->once();
    $draftReceipt->shouldReceive('void')->once();
    $draftReceipt->shouldReceive('calculateTotals')->twice();

    $voidReceipt = new RefundReceipt();
    $voidReceipt->forceFill(['status' => 'void']);

    $processVisible = $readProperty($processAction, 'isVisible');
    $voidVisible = $readProperty($voidAction, 'isVisible');

    expect($processVisible($draftReceipt))->toBeTrue()
        ->and($processVisible($voidReceipt))->toBeFalse()
        ->and($voidVisible($draftReceipt))->toBeTrue()
        ->and($voidVisible($voidReceipt))->toBeFalse();

    ($processAction->getActionFunction())($draftReceipt);
    ($voidAction->getActionFunction())($draftReceipt);

    $createPage->record = $draftReceipt;
    $invokeProtected($createPage, 'afterCreate');

    $editPage->record = $draftReceipt;
    $invokeProtected($editPage, 'afterSave');
});

it('covers ReminderSettingResource and its pages', function () use ($invokeProtected): void {
    $listPage = new ListReminderSettings();
    $createPage = new CreateReminderSetting();
    $editPage = new EditReminderSetting();

    expect($listPage::getResource())->toBe(ReminderSettingResource::class)
        ->and($createPage::getResource())->toBe(ReminderSettingResource::class)
        ->and($editPage::getResource())->toBe(ReminderSettingResource::class)
        ->and(ReminderSettingResource::getNavigationGroup())->toBe('Settings')
        ->and($invokeProtected($listPage, 'getHeaderActions'))->toBeArray()->toHaveCount(1)
        ->and($invokeProtected($editPage, 'getHeaderActions'))->toBeArray()->toHaveCount(1)
        ->and(ReminderSettingResource::getPages())->toHaveKeys(['index', 'edit'])
        ->and(ReminderSettingResource::form(Schema::make($listPage)))->toBeInstanceOf(Schema::class)
        ->and(ReminderSettingResource::table(Table::make($listPage)))->toBeInstanceOf(Table::class);
});

it('covers SalesReceiptResource and its pages including tax recalculation and actions', function () use ($findSchemaComponent, $readProperty, $invokeProtected): void {
    $listPage = new ListSalesReceipts();
    $createPage = new CreateSalesReceipt();
    $editPage = new EditSalesReceipt();

    expect($listPage::getResource())->toBe(SalesReceiptResource::class)
        ->and($createPage::getResource())->toBe(SalesReceiptResource::class)
        ->and($editPage::getResource())->toBe(SalesReceiptResource::class)
        ->and(SalesReceiptResource::getRelations())->toBeArray()
        ->and(SalesReceiptResource::getPages())->toHaveKeys(['index', 'create', 'edit']);

    $schema = SalesReceiptResource::form(Schema::make($listPage)->model(SalesReceipt::class));
    $table = SalesReceiptResource::table(Table::make($listPage));

    $receiptNumber = $findSchemaComponent($schema, 'sales_receipt_number');
    $visibleFn = $readProperty($receiptNumber, 'isVisible');
    expect($visibleFn(null))->toBeFalse()
        ->and($visibleFn(new SalesReceipt()))->toBeTrue();

    $taxRate = TaxRate::create(['name' => 'Standard VAT', 'rate' => 20]);
    $taxRateField = $findSchemaComponent($schema, 'tax_rate_id');
    $hooks = $readProperty($taxRateField, 'afterStateUpdated');
    $captured = [];
    $set = static function (string $key, mixed $value) use (&$captured): void {
        $captured[$key] = $value;
    };
    $get = static fn (string $key): mixed => $key === 'subtotal_amount' ? 150.0 : null;

    $hooks[0]($taxRate->tax_rate_id, $set, $get);
    expect($captured['tax_amount'])->toBe(30.0);

    $captured = [];
    $hooks[0](null, $set, $get);
    expect($captured)->toBeEmpty();

    $itemsRepeater = $findSchemaComponent($schema, 'items');
    $itemLabelFn = $readProperty($itemsRepeater, 'itemLabel');
    expect($itemLabelFn(['description' => 'Consulting']))->toBe('Consulting')
        ->and($itemLabelFn([]))->toBeNull();

    $methodColumn = $table->getColumn('payment_method');
    expect($methodColumn)->toBeInstanceOf(TextColumn::class)
        ->and($methodColumn->formatState('bank_transfer'))->toBe('Bank Transfer');

    $voidAction = $table->getAction('void');
    $completedReceipt = Mockery::mock(SalesReceipt::class)->makePartial();
    $completedReceipt->forceFill(['status' => 'completed']);
    $completedReceipt->shouldReceive('void')->once();
    $completedReceipt->shouldReceive('calculateTotals')->twice();

    $voidReceipt = new SalesReceipt();
    $voidReceipt->forceFill(['status' => 'void']);

    $voidVisible = $readProperty($voidAction, 'isVisible');
    expect($voidVisible($completedReceipt))->toBeTrue()
        ->and($voidVisible($voidReceipt))->toBeFalse();

    ($voidAction->getActionFunction())($completedReceipt);

    $createPage->record = $completedReceipt;
    $invokeProtected($createPage, 'afterCreate');

    $editPage->record = $completedReceipt;
    $invokeProtected($editPage, 'afterSave');
});

it('covers SubscriptionResource and its pages including pause, resume, and cancel actions', function () use ($readProperty, $invokeProtected): void {
    $listPage = new ListSubscriptions();
    $createPage = new CreateSubscription();
    $editPage = new EditSubscription();

    expect($listPage::getResource())->toBe(SubscriptionResource::class)
        ->and($createPage::getResource())->toBe(SubscriptionResource::class)
        ->and($editPage::getResource())->toBe(SubscriptionResource::class)
        ->and($invokeProtected($listPage, 'getHeaderActions'))->toBeArray()->toHaveCount(1)
        ->and($invokeProtected($editPage, 'getHeaderActions'))->toBeArray()->toHaveCount(1)
        ->and(SubscriptionResource::getRelations())->toBeArray()
        ->and(SubscriptionResource::getPages())->toHaveKeys(['index', 'create', 'edit']);

    $schema = SubscriptionResource::form(Schema::make($listPage));
    $table = SubscriptionResource::table(Table::make($listPage));

    expect($schema)->toBeInstanceOf(Schema::class)
        ->and($table)->toBeInstanceOf(Table::class);

    $pauseAction = $table->getAction('pause');
    $resumeAction = $table->getAction('resume');
    $cancelAction = $table->getAction('cancel');

    $activeSub = Mockery::mock(Subscription::class)->makePartial();
    $activeSub->forceFill(['status' => 'active']);
    $activeSub->shouldReceive('pause')->once();
    $activeSub->shouldReceive('cancel')->once();

    $pausedSub = Mockery::mock(Subscription::class)->makePartial();
    $pausedSub->forceFill(['status' => 'paused']);
    $pausedSub->shouldReceive('resume')->once();

    $cancelledSub = new Subscription();
    $cancelledSub->forceFill(['status' => 'cancelled']);

    $pauseVisible = $readProperty($pauseAction, 'isVisible');
    $resumeVisible = $readProperty($resumeAction, 'isVisible');
    $cancelVisible = $readProperty($cancelAction, 'isVisible');

    expect($pauseVisible($activeSub))->toBeTrue()
        ->and($pauseVisible($pausedSub))->toBeFalse()
        ->and($resumeVisible($pausedSub))->toBeTrue()
        ->and($resumeVisible($activeSub))->toBeFalse()
        ->and($cancelVisible($activeSub))->toBeTrue()
        ->and($cancelVisible($pausedSub))->toBeTrue()
        ->and($cancelVisible($cancelledSub))->toBeFalse();

    ($pauseAction->getActionFunction())($activeSub);
    ($resumeAction->getActionFunction())($pausedSub);
    ($cancelAction->getActionFunction())($activeSub);
});

it('covers SupplierResource and its pages', function () use ($invokeProtected): void {
    $listPage = new ListSuppliers();
    $createPage = new CreateSupplier();
    $editPage = new EditSupplier();

    expect($listPage::getResource())->toBe(SupplierResource::class)
        ->and($createPage::getResource())->toBe(SupplierResource::class)
        ->and($editPage::getResource())->toBe(SupplierResource::class)
        ->and($invokeProtected($listPage, 'getHeaderActions'))->toBeArray()->toHaveCount(1)
        ->and($invokeProtected($editPage, 'getHeaderActions'))->toBeArray()->toHaveCount(1)
        ->and(SupplierResource::getRelations())->toBeArray()
        ->and(SupplierResource::getPages())->toHaveKeys(['index', 'create', 'edit'])
        ->and(SupplierResource::form(Schema::make($listPage)))->toBeInstanceOf(Schema::class)
        ->and(SupplierResource::table(Table::make($listPage)))->toBeInstanceOf(Table::class);
});

it('covers TaxFormResource and its pages including generate_pdf action', function () use ($invokeProtected): void {
    $listPage = new ListTaxForms();
    $createPage = new CreateTaxForm();
    $editPage = new EditTaxForm();

    expect($listPage::getResource())->toBe(TaxFormResource::class)
        ->and($createPage::getResource())->toBe(TaxFormResource::class)
        ->and($editPage::getResource())->toBe(TaxFormResource::class)
        ->and($invokeProtected($listPage, 'getHeaderActions'))->toBeArray()->toHaveCount(1)
        ->and($invokeProtected($editPage, 'getHeaderActions'))->toBeArray()->toHaveCount(1)
        ->and(TaxFormResource::getPages())->toHaveKeys(['index', 'create', 'edit']);

    $schema = TaxFormResource::form(Schema::make($listPage));
    $table = TaxFormResource::table(Table::make($listPage));

    expect($schema)->toBeInstanceOf(Schema::class)
        ->and($table)->toBeInstanceOf(Table::class);

    $pdfAction = $table->getAction('generate_pdf');
    expect($pdfAction)->not->toBeNull();

    $taxForm = Mockery::mock(TaxForm::class)->makePartial();
    $taxForm->shouldReceive('generatePDF')->once()->andReturn('pdf-download');

    expect(($pdfAction->getActionFunction())($taxForm))->toBe('pdf-download');
});

it('covers TaxRateResource and its pages', function () use ($invokeProtected): void {
    $listPage = new ListTaxRates();
    $createPage = new CreateTaxRate();
    $editPage = new EditTaxRate();

    expect($listPage::getResource())->toBe(TaxRateResource::class)
        ->and($createPage::getResource())->toBe(TaxRateResource::class)
        ->and($editPage::getResource())->toBe(TaxRateResource::class)
        ->and($invokeProtected($listPage, 'getHeaderActions'))->toBeArray()->toHaveCount(1)
        ->and($invokeProtected($editPage, 'getHeaderActions'))->toBeArray()->toHaveCount(1)
        ->and(TaxRateResource::getPages())->toHaveKeys(['index', 'create', 'edit'])
        ->and(TaxRateResource::form(Schema::make($listPage)))->toBeInstanceOf(Schema::class)
        ->and(TaxRateResource::table(Table::make($listPage)))->toBeInstanceOf(Table::class);
});

it('covers TransactionResource and its pages including currency exchange rate hook', function () use ($findSchemaComponent, $readProperty, $invokeProtected): void {
    $listPage = new ListTransactions();
    $createPage = new CreateTransaction();
    $editPage = new EditTransaction();

    expect($listPage::getResource())->toBe(TransactionResource::class)
        ->and($createPage::getResource())->toBe(TransactionResource::class)
        ->and($editPage::getResource())->toBe(TransactionResource::class)
        ->and($invokeProtected($listPage, 'getHeaderActions'))->toBeArray()->toHaveCount(1)
        ->and($invokeProtected($editPage, 'getHeaderActions'))->toBeArray()->toHaveCount(1)
        ->and(TransactionResource::getRelations())->toBeArray()
        ->and(TransactionResource::getPages())->toHaveKeys(['index', 'create', 'edit']);

    $schema = TransactionResource::form(Schema::make($listPage));
    $table = TransactionResource::table(Table::make($listPage));

    expect($schema)->toBeInstanceOf(Schema::class)
        ->and($table)->toBeInstanceOf(Table::class);

    $usd = Currency::create([
        'code' => 'USD',
        'name' => 'US Dollar',
        'symbol' => '$',
        'is_default' => true,
    ]);
    $eur = Currency::create([
        'code' => 'EUR',
        'name' => 'Euro',
        'symbol' => '€',
        'is_default' => false,
    ]);

    $exchangeService = Mockery::mock(ExchangeRateService::class);
    $exchangeService->shouldReceive('getExchangeRate')->once()->andReturn(1.12);
    $this->app->instance(ExchangeRateService::class, $exchangeService);

    $currencyField = $findSchemaComponent($schema, 'currency_id');
    $hooks = $readProperty($currencyField, 'afterStateUpdated');
    $captured = [];
    $set = static function (string $key, mixed $value) use (&$captured): void {
        $captured[$key] = $value;
    };

    $hooks[0]($eur->currency_id, $set);
    expect($captured['exchange_rate'])->toBe(1.12);

    $hooks[0]($usd->currency_id, $set);
    expect($captured['exchange_rate'])->toBe(1);

    $captured = [];
    $hooks[0](null, $set);
    expect($captured)->toBeEmpty();
});

it('covers VendorCreditResource and its pages including apply_to_bill, void, and lifecycle hooks', function () use ($findSchemaComponent, $readProperty, $invokeProtected): void {
    $listPage = new ListVendorCredits();
    $createPage = new CreateVendorCredit();
    $editPage = new EditVendorCredit();

    expect($listPage::getResource())->toBe(VendorCreditResource::class)
        ->and($createPage::getResource())->toBe(VendorCreditResource::class)
        ->and($editPage::getResource())->toBe(VendorCreditResource::class)
        ->and(VendorCreditResource::getRelations())->toBeArray()
        ->and(VendorCreditResource::getPages())->toHaveKeys(['index', 'create', 'edit']);

    $schema = VendorCreditResource::form(Schema::make($listPage)->model(VendorCredit::class));
    $table = VendorCreditResource::table(Table::make($listPage));

    $creditNumber = $findSchemaComponent($schema, 'vendor_credit_number');
    $amountRemaining = $findSchemaComponent($schema, 'amount_remaining');
    $applications = $findSchemaComponent($schema, 'applications');

    $creditInstance = new VendorCredit();
    expect($readProperty($creditNumber, 'isVisible')(null))->toBeFalse()
        ->and($readProperty($creditNumber, 'isVisible')($creditInstance))->toBeTrue()
        ->and($readProperty($amountRemaining, 'isVisible')(null))->toBeFalse()
        ->and($readProperty($amountRemaining, 'isVisible')($creditInstance))->toBeTrue()
        ->and($readProperty($applications, 'isVisible')(null))->toBeFalse()
        ->and($readProperty($applications, 'isVisible')($creditInstance))->toBeTrue();

    $taxRate = TaxRate::create(['name' => 'Vendor VAT', 'rate' => 10]);
    $taxRateField = $findSchemaComponent($schema, 'tax_rate_id');
    $hooks = $readProperty($taxRateField, 'afterStateUpdated');
    $captured = [];
    $set = static function (string $key, mixed $value) use (&$captured): void {
        $captured[$key] = $value;
    };
    $get = static fn (string $key): mixed => $key === 'subtotal_amount' ? 200.0 : null;

    $hooks[0]($taxRate->tax_rate_id, $set, $get);
    expect($captured['tax_amount'])->toBe(20.0);

    $captured = [];
    $hooks[0](null, $set, $get);
    expect($captured)->toBeEmpty();

    $itemsRepeater = $findSchemaComponent($schema, 'items');
    $itemLabelFn = $readProperty($itemsRepeater, 'itemLabel');
    expect($itemLabelFn(['description' => 'Damaged Goods']))->toBe('Damaged Goods')
        ->and($itemLabelFn([]))->toBeNull();

    $applyAction = $table->getAction('apply_to_bill');
    $voidAction = $table->getAction('void');

    $openCredit = Mockery::mock(VendorCredit::class)->makePartial();
    $openCredit->forceFill(['status' => 'open', 'amount_remaining' => 75.50]);
    $openCredit->shouldReceive('applyToBill')->once()->with(12, 50.0);
    $openCredit->shouldReceive('void')->once();
    $openCredit->shouldReceive('calculateTotals')->twice();

    $exhaustedCredit = new VendorCredit();
    $exhaustedCredit->forceFill(['status' => 'applied', 'amount_remaining' => 0]);

    $voidCredit = new VendorCredit();
    $voidCredit->forceFill(['status' => 'void', 'amount_remaining' => 50]);

    $applyVisible = $readProperty($applyAction, 'isVisible');
    $voidVisible = $readProperty($voidAction, 'isVisible');

    expect($applyVisible($openCredit))->toBeTrue()
        ->and($applyVisible($exhaustedCredit))->toBeFalse()
        ->and($applyVisible($voidCredit))->toBeFalse()
        ->and($voidVisible($openCredit))->toBeTrue()
        ->and($voidVisible($voidCredit))->toBeFalse();

    $actionSchema = $applyAction->getSchema(Schema::make($listPage));
    $amountField = $findSchemaComponent($actionSchema, 'amount');
    $maxValueFn = $readProperty($amountField, 'maxValue');
    expect((float) $maxValueFn($openCredit))->toBe(75.5);

    ($applyAction->getActionFunction())($openCredit, ['bill_id' => 12, 'amount' => 50.0]);
    ($voidAction->getActionFunction())($openCredit);

    $createPage->record = $openCredit;
    $invokeProtected($createPage, 'afterCreate');

    $editPage->record = $openCredit;
    $invokeProtected($editPage, 'afterSave');
});
