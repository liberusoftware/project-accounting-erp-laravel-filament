<?php

declare(strict_types=1);

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Liberu\Accounting\Erp\Actions\SaveBillOfMaterials;
use Liberu\Accounting\Erp\Models\Account;
use Liberu\Accounting\Erp\Models\BankConnection;
use Liberu\Accounting\Erp\Models\BankStatement;
use Liberu\Accounting\Erp\Models\Bill;
use Liberu\Accounting\Erp\Models\Company;
use Liberu\Accounting\Erp\Models\ConsolidationGroup;
use Liberu\Accounting\Erp\Models\CreditMemo;
use Liberu\Accounting\Erp\Models\Customer;
use Liberu\Accounting\Erp\Models\DelayedCharge;
use Liberu\Accounting\Erp\Models\Document;
use Liberu\Accounting\Erp\Models\DocumentVersion;
use Liberu\Accounting\Erp\Models\HmrcVatReturn;
use Liberu\Accounting\Erp\Models\InventoryItem;
use Liberu\Accounting\Erp\Models\Invoice;
use Liberu\Accounting\Erp\Models\JournalEntry;
use Liberu\Accounting\Erp\Models\TaxRate;
use Liberu\Accounting\Erp\Models\Team;
use Liberu\Accounting\Erp\Models\Transaction;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\Erp\Notifications\ImportFailedNotification;
use Liberu\Accounting\Erp\Notifications\PortalAccessNotification;
use Liberu\Accounting\Erp\Services\AccountCsvService;
use Liberu\Accounting\Erp\Services\BankStatementImportService;
use Liberu\Accounting\Erp\Services\DocumentService;
use Liberu\Accounting\Erp\Services\HmrcMtdVatService;
use Liberu\Accounting\Erp\Services\InventoryMovementService;
use Liberu\Accounting\Erp\Services\InvoicePostingService;
use Liberu\Accounting\Erp\Services\PlaidService;
use Liberu\Accounting\Erp\Services\PostingReversalService;
use Liberu\Accounting\Erp\Services\ReconciliationService;
use Liberu\Accounting\Erp\Services\TeamManagementService;
use Liberu\Accounting\Erp\Services\TenantProvisioningService;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\ApprovalRules\ApprovalRuleResource;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\ApprovalRules\Pages\CreateApprovalRule;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\ApprovalRules\Pages\EditApprovalRule;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\ApprovalRules\Pages\ListApprovalRules;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\AssetAcquisitions\AssetAcquisitionResource;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\AssetAcquisitions\Pages\CreateAssetAcquisition;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\AssetAcquisitions\Pages\EditAssetAcquisition;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\AssetAcquisitions\Pages\ListAssetAcquisitions;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\BankConnections\BankConnectionResource;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\BankConnections\Pages\CreateBankConnection;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\BankConnections\Pages\EditBankConnection;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\BankConnections\Pages\ListBankConnections;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\BankConnections\Pages\ViewBankConnection;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\BankStatements\BankStatementResource;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\BankStatements\Pages\CreateBankStatement;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\BankStatements\Pages\EditBankStatement;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\BankStatements\Pages\ListBankStatements;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\Bills\BillResource;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\Bills\Pages\CreateBill;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\Bills\Pages\EditBill;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\Bills\Pages\ListBills;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\Categories\CategoryResource;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\Categories\Pages\CreateCategory;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\Categories\Pages\EditCategory;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\Categories\Pages\ListCategories;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\ChartOfAccounts\ChartOfAccountsResource;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\ChartOfAccounts\Pages\CreateChartOfAccounts;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\ChartOfAccounts\Pages\EditChartOfAccounts;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\ChartOfAccounts\Pages\ListChartOfAccounts;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\Companies\CompanyResource;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\Companies\Pages\CreateCompany;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\Companies\Pages\EditCompany;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\Companies\Pages\ListCompanies;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\ConsolidationGroups\ConsolidationGroupResource;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\ConsolidationGroups\Pages\CreateConsolidationGroup;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\ConsolidationGroups\Pages\EditConsolidationGroup;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\ConsolidationGroups\Pages\ListConsolidationGroups;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\CreditMemos\CreditMemoResource;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\CreditMemos\Pages\CreateCreditMemo;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\CreditMemos\Pages\EditCreditMemo;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\CreditMemos\Pages\ListCreditMemos;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\Customers\CustomerResource;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\Customers\Pages\CreateCustomer;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\Customers\Pages\EditCustomer;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\Customers\Pages\ListCustomers;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\DelayedCharges\DelayedChargeResource;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\DelayedCharges\Pages\CreateDelayedCharge;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\DelayedCharges\Pages\EditDelayedCharge;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\DelayedCharges\Pages\ListDelayedCharges;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\ForecastScenarios\ForecastScenarioResource;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\ForecastScenarios\Pages\CreateForecastScenario;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\ForecastScenarios\Pages\EditForecastScenario;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\ForecastScenarios\Pages\ListForecastScenarios;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\HmrcVatReturns\HmrcVatReturnResource;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\HmrcVatReturns\Pages\CreateHmrcVatReturn;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\HmrcVatReturns\Pages\EditHmrcVatReturn;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\HmrcVatReturns\Pages\ListHmrcVatReturns;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\InventoryItems\BillOfMaterialsAction;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\InventoryItems\InventoryItemResource;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\InventoryItems\Pages\CreateInventoryItem;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\InventoryItems\Pages\EditInventoryItem;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\InventoryItems\Pages\ListInventoryItems;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\Invoices\InvoiceResource;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\Invoices\Pages\CreateInvoice;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\Invoices\Pages\EditInvoice;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\Invoices\Pages\ListInvoices;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\Invoices\RelationManagers\DocumentsRelationManager;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\JournalEntries\JournalEntryResource;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\JournalEntries\Pages\CreateJournalEntry;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\JournalEntries\Pages\EditJournalEntry;
use Liberu\Accounting\ErpFilament\Filament\App\Resources\JournalEntries\Pages\ListJournalEntries;
use Liberu\Foundation\RolesPermissions\Models\Role;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    Http::fake();
    Mail::fake();
    Queue::fake();
    Storage::fake('public');
});

/**
 * @return array{user: User, team: Team}
 */
function erpPart1AppTenantActor(): array
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $team = app(TeamManagementService::class)->createPersonalTeamForUser($user);
    $team->forceFill(['accounting_setup_completed_at' => now()])->save();
    $user->unsetRelation('currentTeam');

    setPermissionsTeamId($team->id);
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web', 'team_id' => $team->id]);
    $user->assignRole('super_admin');

    return ['user' => $user, 'team' => $team];
}

it('covers ApprovalRules and AssetAcquisitions resources and pages', function (): void {
    ['user' => $user, 'team' => $team] = erpPart1AppTenantActor();
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($team);

    // ApprovalRules
    $listHost = new ListApprovalRules();
    $createHost = new CreateApprovalRule();
    $editHost = new EditApprovalRule();

    expect(ApprovalRuleResource::getEloquentQuery())->toBeInstanceOf(Builder::class)
        ->and(ApprovalRuleResource::form(Schema::make($createHost)))->toBeInstanceOf(Schema::class)
        ->and(ApprovalRuleResource::table(Table::make($listHost)))->toBeInstanceOf(Table::class)
        ->and(ApprovalRuleResource::getRelations())->toBe([])
        ->and(ApprovalRuleResource::getPages())->toHaveKeys(['index', 'create', 'edit'])
        ->and(Closure::bind(fn () => $this->getHeaderActions(), $listHost, ListApprovalRules::class)())->toHaveCount(1)
        ->and(Closure::bind(fn () => $this->getHeaderActions(), $editHost, EditApprovalRule::class)())->toHaveCount(1);

    $mutated = Closure::bind(
        fn (array $data): array => $this->mutateFormDataBeforeCreate($data),
        $createHost,
        CreateApprovalRule::class
    )(['approvable_type' => 'Invoice']);
    expect($mutated['team_id'])->toBe($team->id);

    // AssetAcquisitions
    $listAa = new ListAssetAcquisitions();
    $createAa = new CreateAssetAcquisition();
    $editAa = new EditAssetAcquisition();

    expect($createAa)->toBeInstanceOf(CreateAssetAcquisition::class)
        ->and(AssetAcquisitionResource::canAccess())->toBeBool()
        ->and(AssetAcquisitionResource::shouldRegisterNavigation())->toBeBool()
        ->and(AssetAcquisitionResource::form(Schema::make($createAa)))->toBeInstanceOf(Schema::class)
        ->and(AssetAcquisitionResource::table(Table::make($listAa)))->toBeInstanceOf(Table::class)
        ->and(AssetAcquisitionResource::getRelations())->toBe([])
        ->and(AssetAcquisitionResource::getPages())->toHaveKeys(['index', 'create', 'edit'])
        ->and(Closure::bind(fn () => $this->getHeaderActions(), $listAa, ListAssetAcquisitions::class)())->toHaveCount(1)
        ->and(Closure::bind(fn () => $this->getHeaderActions(), $editAa, EditAssetAcquisition::class)())->toHaveCount(1);
});

it('covers BankConnections resource, pages, filters, and Plaid actions', function (): void {
    ['user' => $user, 'team' => $team] = erpPart1AppTenantActor();
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($team);

    $connection = BankConnection::forceCreate([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'bank_id' => 'bank-001',
        'institution_name' => 'Barclays',
        'status' => 'active',
        'plaid_item_id' => 'item-123',
        'plaid_access_token' => 'access-token-123',
    ]);

    $listHost = new ListBankConnections();
    $createHost = new CreateBankConnection();
    $editHost = new EditBankConnection();
    $viewHost = new ViewBankConnection();

    // Form + section collapsed closure
    $form = BankConnectionResource::form(Schema::make($createHost))->model($connection);
    foreach ($form->getFlatComponents(withActions: false, withHidden: true) as $component) {
        if (method_exists($component, 'isCollapsed')) {
            $component->isCollapsed();
        }
    }

    expect(BankConnectionResource::getRelations())->toBe([])
        ->and(BankConnectionResource::getPages())->toHaveKeys(['index', 'create', 'view', 'edit']);

    $mutated = Closure::bind(
        fn (array $data): array => $this->mutateFormDataBeforeCreate($data),
        $createHost,
        CreateBankConnection::class
    )(['bank_id' => 'b-2']);
    expect($mutated['user_id'])->toBe($user->id);
    expect(Closure::bind(fn () => $this->getHeaderActions(), $editHost, EditBankConnection::class)())->toHaveCount(2);

    // Table columns + filters
    $table = BankConnectionResource::table(Table::make($listHost));
    /** @var IconColumn $statusCol */
    $statusCol = $table->getColumn('status');
    foreach (['active', 'inactive', 'error', 'pending', 'unknown'] as $st) {
        expect($statusCol->getIcon($st))->toBeString()
            ->and($statusCol->getColor($st))->toBeString();
    }
    /** @var TextColumn $plaidCol */
    $plaidCol = $table->getColumn('plaid_item_id');
    expect($plaidCol->formatState('item-1'))->toBe('Yes')
        ->and($plaidCol->formatState(null))->toBe('No')
        ->and($plaidCol->getColor('item-1'))->toBe('success')
        ->and($plaidCol->getColor(null))->toBe('gray');

    /** @var TernaryFilter $plaidFilter */
    $plaidFilter = $table->getFilter('plaid_connected');
    $plaidFilter->apply(BankConnection::query(), ['value' => true]);
    $plaidFilter->apply(BankConnection::query(), ['value' => false]);

    // Record actions: sync_transactions (success + exception) and disconnect (success + exception)
    $plaidMock = Mockery::mock(PlaidService::class);
    $plaidMock->shouldReceive('syncTransactions')->once()->andReturn(['added' => [1], 'modified' => [], 'removed' => []]);
    $plaidMock->shouldReceive('syncTransactions')->once()->andThrow(new Exception('Plaid down'));
    $plaidMock->shouldReceive('removeItem')->once()->andThrow(new Exception('Remove failed'));
    $plaidMock->shouldReceive('removeItem')->once()->andReturn(true);
    $plaidMock->shouldReceive('createLinkToken')->once()->andReturn(['link_token' => 'link-sandbox-xyz']);
    $plaidMock->shouldReceive('createLinkToken')->once()->andThrow(new Exception('Link error'));
    $plaidMock->shouldReceive('syncTransactions')->once()->andReturn(['added' => [], 'modified' => [], 'removed' => []]);
    $plaidMock->shouldReceive('syncTransactions')->once()->andThrow(new Exception('View sync fail'));
    app()->instance(PlaidService::class, $plaidMock);

    foreach ($table->getRecordActions() as $action) {
        if (! $action instanceof Action) {
            continue;
        }
        if ($action->getName() === 'sync_transactions') {
            $action->record($connection);
            expect($action->isVisible())->toBeTrue();
            $action->evaluate($action->getActionFunction(), ['record' => $connection]);
            $action->evaluate($action->getActionFunction(), ['record' => $connection]);
        }
        if ($action->getName() === 'disconnect') {
            $action->record($connection);
            expect($action->isVisible())->toBeTrue();
            $action->evaluate($action->getActionFunction(), ['record' => $connection]);
            $action->evaluate($action->getActionFunction(), ['record' => $connection]);
        }
    }

    // ListBankConnections header action connect_plaid (success + exception)
    foreach (Closure::bind(fn () => $this->getHeaderActions(), $listHost, ListBankConnections::class)() as $action) {
        if ($action instanceof Action && $action->getName() === 'connect_plaid') {
            $action->evaluate($action->getActionFunction());
            $action->evaluate($action->getActionFunction());
        }
    }

    // ViewBankConnection header action sync_transactions (success + exception)
    $connection->forceFill(['plaid_item_id' => 'item-999'])->save();
    $viewHost->record = $connection;
    foreach (Closure::bind(fn () => $this->getHeaderActions(), $viewHost, ViewBankConnection::class)() as $action) {
        if ($action instanceof Action && $action->getName() === 'sync_transactions') {
            expect($action->isVisible())->toBeTrue();
            $action->evaluate($action->getActionFunction());
            $action->evaluate($action->getActionFunction());
        }
    }
});

it('covers BankStatements resource, pages, filters, import, reconcile, and discrepancies actions', function (): void {
    Notification::fake();

    ['user' => $user, 'team' => $team] = erpPart1AppTenantActor();
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($team);

    $account = Account::forceCreate([
        'team_id' => $team->id,
        'account_number' => '1001',
        'account_name' => 'Checking',
        'account_type' => 'asset',
        'normal_balance' => 'debit',
        'is_active' => true,
    ]);
    $statement = BankStatement::forceCreate([
        'team_id' => $team->id,
        'account_id' => $account->id,
        'statement_date' => '2026-01-31',
        'total_credits' => 500,
        'total_debits' => 200,
        'ending_balance' => 300,
        'reconciled' => false,
    ]);
    Transaction::forceCreate([
        'team_id' => $team->id,
        'bank_statement_id' => $statement->id,
        'transaction_date' => '2026-01-15',
        'amount' => 100,
        'description' => 'Deposit',
    ]);

    $listHost = new ListBankStatements();
    $createHost = new CreateBankStatement();
    $editHost = new EditBankStatement();

    foreach ([$createHost, $editHost] as $host) {
        $form = BankStatementResource::form(Schema::make($host));
        foreach ($form->getFlatComponents(withActions: false, withHidden: true) as $component) {
            $component->isVisible();
        }
    }

    expect(BankStatementResource::getRelations())->toBe([])
        ->and(BankStatementResource::getPages())->toHaveKeys(['index', 'create', 'edit'])
        ->and(Closure::bind(fn () => $this->getHeaderActions(), $listHost, ListBankStatements::class)())->toHaveCount(1)
        ->and(Closure::bind(fn () => $this->getHeaderActions(), $editHost, EditBankStatement::class)())->toHaveCount(1);

    $table = BankStatementResource::table(Table::make($listHost));
    $table->getFilter('date')->apply(BankStatement::query(), ['from' => '2026-01-01', 'until' => '2026-01-31']);

    $importMock = Mockery::mock(BankStatementImportService::class);
    $importMock->shouldReceive('importFromCsv')->twice()->andReturn(new Collection([1, 2]));
    $importMock->shouldReceive('importFromOfx')->once()->andReturn(new Collection([1]));
    app()->instance(BankStatementImportService::class, $importMock);

    $reconMock = Mockery::mock(ReconciliationService::class);
    $reconMock->shouldReceive('reconcileStatement')->once()->andReturn([
        'reconciled' => true,
        'matched_transactions' => 1,
        'unmatched_transactions' => 0,
        'balance_discrepancy' => 0.0,
    ]);
    $reconMock->shouldReceive('reconcileStatement')->once()->andReturn([
        'reconciled' => false,
        'matched_transactions' => 0,
        'unmatched_transactions' => 1,
        'balance_discrepancy' => 12.50,
    ]);
    $reconMock->shouldReceive('reconcile')->once()->andReturn([
        'reconciled' => false,
        'matched_transactions' => 0,
        'unmatched_transactions' => 1,
        'balance_discrepancy' => 12.50,
        'unmatched' => [],
        'discrepancies' => [],
    ]);
    app()->instance(ReconciliationService::class, $reconMock);

    foreach ($table->getRecordActions() as $action) {
        if (! $action instanceof Action) {
            continue;
        }
        if ($action->getName() === 'import') {
            $action->evaluate($action->getActionFunction(), ['record' => $statement, 'data' => ['statement_file' => 'stmt.csv']]);
            $action->evaluate($action->getActionFunction(), ['record' => $statement, 'data' => ['statement_file' => 'stmt.ofx']]);
            $action->evaluate($action->getActionFunction(), ['record' => $statement, 'data' => ['statement_file' => 'stmt.xlsx']]);
            $action->evaluate($action->getActionFunction(), ['record' => $statement, 'data' => ['statement_file' => 'stmt.pdf']]);
        }
        if ($action->getName() === 'reconcile') {
            $action->record($statement);
            $action->isVisible();
            $action->evaluate($action->getActionFunction(), ['record' => $statement]);
            $action->evaluate($action->getActionFunction(), ['record' => $statement]);
        }
        if ($action->getName() === 'view_discrepancies') {
            $action->record($statement);
            $action->isVisible();
            $action->getModalContent();
        }
    }

    Notification::assertSentTo($user, ImportFailedNotification::class);
});

it('covers Bills, Categories, ChartOfAccounts, and Companies resources and pages', function (): void {
    ['user' => $user, 'team' => $team] = erpPart1AppTenantActor();
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($team);

    // Bills
    $listBills = new ListBills();
    $createBill = new CreateBill();
    $editBill = new EditBill();
    $taxRate = TaxRate::forceCreate(['team_id' => $team->id, 'name' => 'VAT 20%', 'rate' => 20]);

    $billForm = BillResource::form(Schema::make($createBill)->model(Bill::class));
    $billForm->fill(['bill_date' => '2026-01-01', 'subtotal_amount' => 100, 'tax_rate_id' => $taxRate->id]);
    foreach ($billForm->getFlatComponents(withActions: false, withHidden: true) as $component) {
        $component->isVisible();
        if (method_exists($component, 'getMinDate')) {
            $component->getMinDate();
        }
        if ($component instanceof Select && $component->getName() === 'tax_rate_id') {
            $component->callAfterStateUpdated();
        }
    }
    expect($editBill)->toBeInstanceOf(EditBill::class)
        ->and(BillResource::table(Table::make($listBills)))->toBeInstanceOf(Table::class)
        ->and(BillResource::getRelations())->toContain(DocumentsRelationManager::class)
        ->and(BillResource::getPages())->toHaveKeys(['index', 'create', 'edit']);

    // Categories
    $listCats = new ListCategories();
    $editCat = new EditCategory();
    expect(new CreateCategory())->toBeInstanceOf(CreateCategory::class)
        ->and(CategoryResource::form(Schema::make($listCats)))->toBeInstanceOf(Schema::class)
        ->and(CategoryResource::table(Table::make($listCats)))->toBeInstanceOf(Table::class)
        ->and(CategoryResource::getRelations())->toBe([])
        ->and(CategoryResource::getPages())->toHaveKeys(['index', 'create', 'edit'])
        ->and(Closure::bind(fn () => $this->getHeaderActions(), $listCats, ListCategories::class)())->toHaveCount(1)
        ->and(Closure::bind(fn () => $this->getHeaderActions(), $editCat, EditCategory::class)())->toHaveCount(1);

    // ChartOfAccounts
    Account::forceCreate([
        'team_id' => $team->id,
        'account_number' => '1000',
        'account_name' => 'Cash',
        'account_type' => 'asset',
        'normal_balance' => 'debit',
        'is_active' => true,
    ]);

    $listCoa = new ListChartOfAccounts();
    $createCoa = new CreateChartOfAccounts();
    $editCoa = new EditChartOfAccounts();

    $coaForm = ChartOfAccountsResource::form(Schema::make($createCoa));
    $coaForm->fill(['account_type' => 'asset', 'parent_id' => null]);
    foreach ($coaForm->getFlatComponents(withActions: false, withHidden: true) as $component) {
        $component->isVisible();
        if (method_exists($component, 'getDefaultState')) {
            $component->getDefaultState();
        }
        if ($component instanceof Select && $component->getName() === 'parent_id') {
            expect($component->getOptions())->toBeArray();
        }
    }

    $coaTable = ChartOfAccountsResource::table(Table::make($listCoa));
    /** @var TextColumn $typeCol */
    $typeCol = $coaTable->getColumn('account_type');
    foreach (['asset', 'liability', 'equity', 'revenue', 'expense', 'other'] as $type) {
        expect($typeCol->getColor($type))->toBeString();
    }
    expect(ChartOfAccountsResource::getPages())->toHaveKeys(['index', 'create', 'edit'])
        ->and(Closure::bind(fn () => $this->getHeaderActions(), $editCoa, EditChartOfAccounts::class)())->toHaveCount(1);

    // ListChartOfAccounts header actions (provisionChart, exportAccounts, importAccounts)
    $provMock = Mockery::mock(TenantProvisioningService::class);
    $provMock->shouldReceive('provisionChartOfAccounts')->once()->andReturn(12);
    $provMock->shouldReceive('provisionChartOfAccounts')->once()->andReturn(0);
    app()->instance(TenantProvisioningService::class, $provMock);

    $csvMock = Mockery::mock(AccountCsvService::class);
    $csvMock->shouldReceive('export')->once()->andReturn("account_number,account_name\n1000,Cash\n");
    $csvMock->shouldReceive('import')->once()->andReturn(['created' => 2, 'updated' => 1, 'errors' => []]);
    $csvMock->shouldReceive('import')->once()->andReturn(['created' => 0, 'updated' => 0, 'errors' => ['Row 2 invalid']]);
    app()->instance(AccountCsvService::class, $csvMock);

    Storage::disk('public')->put('accounts.csv', "account_number,account_name\n1000,Cash\n");

    foreach (Closure::bind(fn () => $this->getHeaderActions(), $listCoa, ListChartOfAccounts::class)() as $action) {
        if (! $action instanceof Action) {
            continue;
        }
        if ($action->getName() === 'provisionChart') {
            $action->evaluate($action->getActionFunction(), ['service' => $provMock]);
            $action->evaluate($action->getActionFunction(), ['service' => $provMock]);
            Filament::setTenant(null);
            $action->evaluate($action->getActionFunction(), ['service' => $provMock]);
            Filament::setTenant($team);
        }
        if ($action->getName() === 'exportAccounts') {
            $resp = $action->evaluate($action->getActionFunction(), ['service' => $csvMock]);
            expect($resp)->toBeInstanceOf(StreamedResponse::class);
            ob_start();
            $resp->sendContent();
            expect(ob_get_clean())->toContain('1000,Cash');
        }
        if ($action->getName() === 'importAccounts') {
            $action->evaluate($action->getActionFunction(), ['data' => ['file' => 'accounts.csv'], 'service' => $csvMock]);
            $action->evaluate($action->getActionFunction(), ['data' => ['file' => 'accounts.csv'], 'service' => $csvMock]);
            expect(fn () => $action->evaluate($action->getActionFunction(), ['data' => ['file' => '../../.env'], 'service' => $csvMock]))
                ->toThrow(HttpException::class);
        }
    }

    // Companies
    $listComp = new ListCompanies();
    $editComp = new EditCompany();
    expect(new CreateCompany())->toBeInstanceOf(CreateCompany::class)
        ->and(CompanyResource::form(Schema::make($listComp)))->toBeInstanceOf(Schema::class)
        ->and(CompanyResource::table(Table::make($listComp)))->toBeInstanceOf(Table::class)
        ->and(CompanyResource::getRelations())->toBe([])
        ->and(CompanyResource::getPages())->toHaveKeys(['index', 'create', 'edit'])
        ->and(Closure::bind(fn () => $this->getHeaderActions(), $listComp, ListCompanies::class)())->toHaveCount(1)
        ->and(Closure::bind(fn () => $this->getHeaderActions(), $editComp, EditCompany::class)())->toHaveCount(1);
});

it('covers ConsolidationGroups, CreditMemos, Customers, DelayedCharges, and ForecastScenarios resources and pages', function (): void {
    Notification::fake();

    ['user' => $user, 'team' => $team] = erpPart1AppTenantActor();
    $otherUser = User::factory()->create();
    $foreignTeam = app(TeamManagementService::class)->createPersonalTeamForUser($otherUser);

    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($team);

    // ConsolidationGroups
    $group = ConsolidationGroup::create(['name' => 'Group A', 'owner_team_id' => $team->id]);
    $group->members()->sync([$team->id, $foreignTeam->id]);

    $listCg = new ListConsolidationGroups();
    $createCg = new CreateConsolidationGroup();
    $editCg = new EditConsolidationGroup();

    expect(ConsolidationGroupResource::getEloquentQuery())->toBeInstanceOf(Builder::class)
        ->and(ConsolidationGroupResource::allowedTeamIds())->toContain($team->id)
        ->and(ConsolidationGroupResource::getRelations())->toBe([])
        ->and(ConsolidationGroupResource::getPages())->toHaveKeys(['index', 'create', 'edit'])
        ->and(Closure::bind(fn () => $this->getHeaderActions(), $listCg, ListConsolidationGroups::class)())->toHaveCount(1)
        ->and(Closure::bind(fn () => $this->getHeaderActions(), $editCg, EditConsolidationGroup::class)())->toHaveCount(1);

    $cgForm = ConsolidationGroupResource::form(Schema::make($createCg)->model(ConsolidationGroup::class));
    foreach ($cgForm->getFlatComponents(withActions: false, withHidden: true) as $component) {
        if ($component instanceof Select && $component->getName() === 'members') {
            expect($component->getOptions())->toBeArray();
        }
    }
    expect(ConsolidationGroupResource::table(Table::make($listCg)))->toBeInstanceOf(Table::class);

    $mutated = Closure::bind(fn (array $d) => $this->mutateFormDataBeforeCreate($d), $createCg, CreateConsolidationGroup::class)(['name' => 'G']);
    expect($mutated['owner_team_id'])->toBe($team->id);

    $createCg->record = $group;
    Closure::bind(fn () => $this->afterCreate(), $createCg, CreateConsolidationGroup::class)();
    expect($group->members()->pluck('teams.id')->all())->toBe([$team->id]);

    $editCg->record = $group;
    Closure::bind(fn () => $this->afterSave(), $editCg, EditConsolidationGroup::class)();

    // Customers
    $customer = Customer::forceCreate([
        'team_id' => $team->id,
        'customer_name' => 'Jane',
        'customer_last_name' => 'Doe',
        'customer_address' => '10 Downing St',
        'customer_email' => 'jane@example.com',
        'customer_phone' => '07700900001',
        'customer_city' => 'London',
        'current_balance' => 100,
        'credit_limit' => 50,
        'credit_hold' => false,
    ]);
    $listCust = new ListCustomers();
    $editCust = new EditCustomer();
    expect(new CreateCustomer())->toBeInstanceOf(CreateCustomer::class)
        ->and(CustomerResource::form(Schema::make($listCust)))->toBeInstanceOf(Schema::class)
        ->and(CustomerResource::getRelations())->toBe([])
        ->and(CustomerResource::getPages())->toHaveKeys(['index', 'create', 'edit'])
        ->and(Closure::bind(fn () => $this->getHeaderActions(), $listCust, ListCustomers::class)())->toHaveCount(1)
        ->and(Closure::bind(fn () => $this->getHeaderActions(), $editCust, EditCustomer::class)())->toHaveCount(1);

    $custTable = CustomerResource::table(Table::make($listCust));
    $custTable->getFilter('customer_city')->getOptions();
    $custTable->getFilter('over_credit_limit')->apply(Customer::query(), ['isActive' => true]);
    foreach ($custTable->getRecordActions() as $action) {
        if ($action instanceof Action && $action->getName() === 'sendPortalInvite') {
            $action->evaluate($action->getActionFunction(), ['record' => $customer]);
        }
    }
    Notification::assertSentTo($customer, PortalAccessNotification::class);

    // CreditMemos
    $listCm = new ListCreditMemos();
    $createCm = new CreateCreditMemo();
    expect(new EditCreditMemo())->toBeInstanceOf(EditCreditMemo::class)
        ->and(CreditMemoResource::getRelations())->toBe([])
        ->and(CreditMemoResource::getPages())->toHaveKeys(['index', 'create', 'edit']);

    $cm = CreditMemo::forceCreate([
        'team_id' => $team->id,
        'customer_id' => $customer->id,
        'credit_memo_number' => 'CM-001',
        'credit_memo_date' => '2026-01-10',
        'subtotal_amount' => 100,
        'tax_amount' => 0,
        'total_amount' => 100,
        'amount_applied' => 25,
        'status' => 'open',
    ]);
    $cmForm = CreditMemoResource::form(Schema::make($createCm))->model($cm);
    foreach ($cmForm->getFlatComponents(withActions: false, withHidden: true) as $component) {
        $component->isVisible();
    }
    $cmTable = CreditMemoResource::table(Table::make($listCm));
    Closure::bind(fn () => $this->table = $cmTable, $listCm, ListCreditMemos::class)();
    $cmTable->getColumn('amount_remaining')->record($cm)->getState();

    // DelayedCharges
    $invoice = Invoice::forceCreate([
        'team_id' => $team->id,
        'customer_id' => $customer->id,
        'invoice_number' => 'INV-DC-1',
        'invoice_date' => '2026-01-15',
        'total_amount' => 200,
        'payment_status' => 'pending',
    ]);
    $charge1 = DelayedCharge::forceCreate([
        'team_id' => $team->id,
        'customer_id' => $customer->id,
        'charge_date' => '2026-01-12',
        'description' => 'Consulting hours',
        'quantity' => 2,
        'unit_price' => 50,
        'amount' => 100,
        'status' => 'pending',
    ]);
    $charge2 = DelayedCharge::forceCreate([
        'team_id' => $team->id,
        'customer_id' => $customer->id,
        'charge_date' => '2026-01-13',
        'description' => 'Support hours',
        'quantity' => 1,
        'unit_price' => 75,
        'amount' => 75,
        'status' => 'pending',
    ]);

    $listDc = new ListDelayedCharges();
    $createDc = new CreateDelayedCharge();
    expect(new EditDelayedCharge())->toBeInstanceOf(EditDelayedCharge::class)
        ->and(DelayedChargeResource::getRelations())->toBe([])
        ->and(DelayedChargeResource::getPages())->toHaveKeys(['index', 'create', 'edit']);

    $dcForm = DelayedChargeResource::form(Schema::make($createDc))->model($charge1);
    $dcForm->fill(['quantity' => 3, 'unit_price' => 40]);
    foreach ($dcForm->getFlatComponents(withActions: false, withHidden: true) as $component) {
        $component->isVisible();
        if (method_exists($component, 'getName') && in_array($component->getName(), ['quantity', 'unit_price'], true)) {
            $component->callAfterStateUpdated();
        }
    }
    $dcTable = DelayedChargeResource::table(Table::make($listDc));
    foreach ($dcTable->getRecordActions() as $action) {
        if (! $action instanceof Action) {
            continue;
        }
        if ($action->getName() === 'add_to_invoice') {
            $action->record($charge1);
            expect($action->isVisible())->toBeTrue();
            $action->evaluate($action->getActionFunction(), ['record' => $charge1, 'data' => ['invoice_id' => $invoice->id]]);
            expect($charge1->fresh()->status)->toBe('invoiced');
        }
        if ($action->getName() === 'void') {
            $action->record($charge2);
            expect($action->isVisible())->toBeTrue();
            $action->evaluate($action->getActionFunction(), ['record' => $charge2]);
            expect($charge2->fresh()->status)->toBe('void');
        }
    }

    // ForecastScenarios
    $listFs = new ListForecastScenarios();
    $editFs = new EditForecastScenario();
    expect(new CreateForecastScenario())->toBeInstanceOf(CreateForecastScenario::class)
        ->and(ForecastScenarioResource::form(Schema::make($listFs)))->toBeInstanceOf(Schema::class)
        ->and(ForecastScenarioResource::table(Table::make($listFs)))->toBeInstanceOf(Table::class)
        ->and(ForecastScenarioResource::getPages())->toHaveKeys(['index', 'create', 'edit'])
        ->and(Closure::bind(fn () => $this->getHeaderActions(), $listFs, ListForecastScenarios::class)())->toHaveCount(1)
        ->and(Closure::bind(fn () => $this->getHeaderActions(), $editFs, EditForecastScenario::class)())->toHaveCount(1);
});

it('covers HmrcVatReturns, InventoryItems, BillOfMaterialsAction, Invoices, DocumentsRelationManager, and JournalEntries', function (): void {
    ['user' => $user, 'team' => $team] = erpPart1AppTenantActor();
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($team);

    $company = Company::forceCreate([
        'team_id' => $team->id,
        'company_name' => 'Liberu Ltd',
        'company_address' => '1 High St',
        'company_email' => 'info@liberu.test',
        'company_phone' => '0123456789',
        'company_city' => 'London',
        'company_tin' => 'GB123456789',
    ]);

    // 1. HmrcVatReturns
    $vatReturn = HmrcVatReturn::forceCreate([
        'company_id' => $company->company_id,
        'period_key' => '26A1',
        'period_from' => '2026-01-01',
        'period_to' => '2026-03-31',
        'due_date' => '2026-05-07',
        'vat_due_sales' => 200,
        'vat_due_acquisitions' => 0,
        'total_vat_due' => 200,
        'vat_reclaimed' => 50,
        'net_vat_due' => 150,
        'total_value_sales' => 1000,
        'total_value_purchases' => 250,
        'total_value_goods_supplied' => 0,
        'total_acquisitions' => 0,
        'finalised' => false,
    ]);

    $listVat = new ListHmrcVatReturns();
    $createVat = new CreateHmrcVatReturn();
    $editVat = new EditHmrcVatReturn();

    expect(HmrcVatReturnResource::form(Schema::make($createVat)))->toBeInstanceOf(Schema::class)
        ->and(HmrcVatReturnResource::getPages())->toHaveKeys(['index', 'create', 'edit'])
        ->and(Closure::bind(fn () => $this->getHeaderActions(), $listVat, ListHmrcVatReturns::class)())->toHaveCount(1)
        ->and(Closure::bind(fn () => $this->getHeaderActions(), $editVat, EditHmrcVatReturn::class)())->toHaveCount(1);

    // mutateFormDataBeforeCreate without company_id throws, with company_id succeeds
    expect(fn () => Closure::bind(fn (array $d) => $this->mutateFormDataBeforeCreate($d), $createVat, CreateHmrcVatReturn::class)([]))
        ->toThrow(Exception::class);
    $team->company_id = $company->company_id;
    $user->setRelation('currentTeam', $team);
    $vatMutated = Closure::bind(fn (array $d) => $this->mutateFormDataBeforeCreate($d), $createVat, CreateHmrcVatReturn::class)([]);
    expect($vatMutated['company_id'])->toBe($company->company_id);

    $hmrcMock = Mockery::mock(HmrcMtdVatService::class);
    $hmrcMock->shouldReceive('submitVatReturn')->once()->andReturn([]);
    $hmrcMock->shouldReceive('submitVatReturn')->once()->andThrow(new Exception('HMRC rejected'));
    app()->instance(HmrcMtdVatService::class, $hmrcMock);

    $vatTable = HmrcVatReturnResource::table(Table::make($listVat));
    Closure::bind(fn () => $this->table = $vatTable, $listVat, ListHmrcVatReturns::class)();
    $vatTable->getColumn('status')->record($vatReturn)->getState();
    foreach ($vatTable->getRecordActions() as $action) {
        if (! $action instanceof Action) {
            continue;
        }
        $action->record($vatReturn);
        $action->isVisible();
        if ($action->getName() === 'calculate') {
            $action->evaluate($action->getActionFunction(), ['record' => $vatReturn]);
        }
        if ($action->getName() === 'submit') {
            $action->evaluate($action->getActionFunction(), ['record' => $vatReturn]);
            $action->evaluate($action->getActionFunction(), ['record' => $vatReturn]);
        }
    }

    // 2. InventoryItems + BillOfMaterialsAction
    $invAccount = Account::forceCreate([
        'team_id' => $team->id,
        'account_number' => '1200',
        'account_name' => 'Inventory Asset',
        'account_type' => 'asset',
        'normal_balance' => 'debit',
        'is_active' => true,
    ]);
    $cogsAccount = Account::forceCreate([
        'team_id' => $team->id,
        'account_number' => '5100',
        'account_name' => 'COGS Expense',
        'account_type' => 'expense',
        'normal_balance' => 'debit',
        'is_active' => true,
    ]);
    $item = InventoryItem::forceCreate([
        'team_id' => $team->id,
        'name' => 'Widget Kit',
        'sku' => 'KIT-001',
        'unit_price' => 50,
        'current_quantity' => 10,
        'reorder_point' => 2,
        'account_id' => $invAccount->id,
        'valuation_method' => 'fifo',
        'is_active' => true,
    ]);
    $componentItem = InventoryItem::forceCreate([
        'team_id' => $team->id,
        'name' => 'Bolt',
        'sku' => 'BLT-001',
        'unit_price' => 2,
        'current_quantity' => 100,
        'reorder_point' => 10,
        'account_id' => $invAccount->id,
        'valuation_method' => 'fifo',
        'is_active' => true,
    ]);

    $listInv = new ListInventoryItems();
    $createInv = new CreateInventoryItem();
    $editInv = new EditInventoryItem();

    expect(InventoryItemResource::canAccess())->toBeBool()
        ->and(InventoryItemResource::shouldRegisterNavigation())->toBeBool()
        ->and(InventoryItemResource::getPages())->toHaveKeys(['index', 'create', 'edit'])
        ->and(Closure::bind(fn () => $this->getHeaderActions(), $listInv, ListInventoryItems::class)())->toHaveCount(1)
        ->and(Closure::bind(fn () => $this->getHeaderActions(), $editInv, EditInventoryItem::class)())->toHaveCount(1);

    $invForm = InventoryItemResource::form(Schema::make($createInv))->operation('create');
    foreach ($invForm->getFlatComponents(withActions: false, withHidden: true) as $comp) {
        if ($comp instanceof Select && $comp->getName() === 'account_id') {
            expect($comp->getOptions())->toBeArray();
        }
    }

    // CreateInventoryItem::handleRecordCreation (valid, foreign account ValidationException, null team 403)
    $createdItem = Closure::bind(fn (array $d) => $this->handleRecordCreation($d), $createInv, CreateInventoryItem::class)([
        'name' => 'New Part',
        'sku' => 'NP-001',
        'unit_price' => 10,
        'current_quantity' => 5,
        'reorder_point' => 1,
        'account_id' => $invAccount->id,
        'valuation_method' => 'fifo',
        'is_active' => true,
    ]);
    expect($createdItem->sku)->toBe('NP-001');

    expect(fn () => Closure::bind(fn (array $d) => $this->handleRecordCreation($d), $createInv, CreateInventoryItem::class)([
        'name' => 'Bad Part',
        'sku' => 'BP-001',
        'unit_price' => 10,
        'current_quantity' => 1,
        'reorder_point' => 0,
        'account_id' => 999999,
        'valuation_method' => 'fifo',
        'is_active' => true,
    ]))->toThrow(ValidationException::class);

    $moveMock = Mockery::mock(InventoryMovementService::class);
    $moveMock->shouldReceive('recordPurchase')->once();
    $moveMock->shouldReceive('recordSale')->once()->andReturn(['cogs' => 25.0]);
    app()->instance(InventoryMovementService::class, $moveMock);

    config([
        'modules.enabled' => array_values(array_unique([
            ...(array) config('modules.enabled', []),
            'accounting-inventory-accounting',
        ])),
    ]);
    $saveBom = app(SaveBillOfMaterials::class);

    $invTable = InventoryItemResource::table(Table::make($listInv));
    foreach ($invTable->getRecordActions() as $action) {
        if (! $action instanceof Action) {
            continue;
        }
        if ($action->getName() === 'recordPurchase') {
            $action->evaluate($action->getActionFunction(), ['record' => $item, 'data' => ['quantity' => 5, 'unit_cost' => 10]]);
        }
        if ($action->getName() === 'recordSale') {
            $action->record($item);
            foreach ($action->getSchema(Schema::make($listInv)->record($item))->getFlatComponents(withActions: false, withHidden: true) as $f) {
                if (method_exists($f, 'getMaxValue')) {
                    $f->getMaxValue();
                }
                if ($f instanceof Select && $f->getName() === 'cogs_account_id') {
                    expect($f->getOptions())->toBeArray();
                }
            }
            $action->evaluate($action->getActionFunction(), ['record' => $item, 'data' => ['quantity' => 2, 'cogs_account_id' => $cogsAccount->id]]);
        }
        if ($action->getName() === 'billOfMaterials') {
            $action->record($item);
            $action->isVisible();
            $action->isAuthorized();
            $schema = $action->getSchema(Schema::make($listInv)->record($item));
            foreach ($schema->getFlatComponents(withActions: false, withHidden: true) as $f) {
                if ($f instanceof Select && $f->getName() === 'component_item_id') {
                    expect($f->getSearchResults('BLT'))->toHaveKey($componentItem->id);
                    $f->state($componentItem->id);
                    expect($f->getOptionLabel())->toContain('BLT-001');
                }
            }
            $action->evaluate($action->getActionFunction(), [
                'record' => $item,
                'data' => ['components' => [['component_item_id' => $componentItem->id, 'quantity' => 4]]],
                'save' => $saveBom,
            ]);
        }
    }

    // 3. Invoices + DocumentsRelationManager
    $customer = Customer::forceCreate([
        'team_id' => $team->id,
        'customer_name' => 'John',
        'customer_last_name' => 'Smith',
        'customer_address' => '221B Baker St',
        'customer_email' => 'john@example.com',
        'customer_phone' => '07700900002',
        'customer_city' => 'London',
    ]);
    $invoice = Invoice::forceCreate([
        'team_id' => $team->id,
        'customer_id' => $customer->id,
        'invoice_number' => 'INV-900',
        'invoice_date' => '2026-01-20',
        'total_amount' => 500,
        'payment_status' => 'pending',
    ]);

    $listInvRec = new ListInvoices();
    $createInvRec = new CreateInvoice();
    $editInvRec = new EditInvoice();

    expect(InvoiceResource::getRelations())->toContain(DocumentsRelationManager::class)
        ->and(InvoiceResource::getPages())->toHaveKeys(['index', 'create', 'edit'])
        ->and(Closure::bind(fn () => $this->getHeaderActions(), $listInvRec, ListInvoices::class)())->toHaveCount(1)
        ->and(Closure::bind(fn () => $this->getHeaderActions(), $editInvRec, EditInvoice::class)())->toHaveCount(1);

    $invoiceForm = InvoiceResource::form(Schema::make($createInvRec))->model($invoice);
    $invoiceForm->fill(['is_recurring' => true, 'recurrence_start' => '2026-01-01']);
    foreach ($invoiceForm->getFlatComponents(withActions: false, withHidden: true) as $c) {
        $c->isVisible();
        if (method_exists($c, 'isRequired')) {
            $c->isRequired();
        }
        if (method_exists($c, 'getMinDate')) {
            $c->getMinDate();
        }
    }

    $postMock = Mockery::mock(InvoicePostingService::class);
    $jeStub = JournalEntry::forceCreate([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'entry_number' => 'JE-POST-1',
        'entry_date' => '2026-01-20',
        'entry_type' => 'general',
        'is_posted' => false,
    ]);
    $postMock->shouldReceive('post')->once()->andReturn($jeStub);
    $postMock->shouldReceive('post')->once()->andThrow(new RuntimeException('Already posted'));
    app()->instance(InvoicePostingService::class, $postMock);

    $revMock = Mockery::mock(PostingReversalService::class);
    $revMock->shouldReceive('reverseInvoice')->once();
    $revMock->shouldReceive('reverseInvoice')->once()->andThrow(new RuntimeException('Cannot reverse'));
    app()->instance(PostingReversalService::class, $revMock);

    $invoiceTable = InvoiceResource::table(Table::make($listInvRec));
    /** @var TextColumn $payCol */
    $payCol = $invoiceTable->getColumn('payment_status');
    foreach (['paid', 'failed', 'pending'] as $st) {
        expect($payCol->getColor($st))->toBeString();
    }

    foreach ($invoiceTable->getRecordActions() as $action) {
        if (! $action instanceof Action) {
            continue;
        }
        if ($action->getName() === 'postToLedger') {
            $action->record($invoice);
            expect($action->isVisible())->toBeTrue();
            $action->evaluate($action->getActionFunction(), ['record' => $invoice]);
            $action->evaluate($action->getActionFunction(), ['record' => $invoice]);
        }
        if ($action->getName() === 'unpost') {
            $invoice->forceFill(['journal_entry_id' => $jeStub->id])->save();
            $action->record($invoice);
            expect($action->isVisible())->toBeTrue();
            $action->evaluate($action->getActionFunction(), ['record' => $invoice]);
            $action->evaluate($action->getActionFunction(), ['record' => $invoice]);
        }
        if ($action->getName() === 'download') {
            $invMock = Mockery::mock($invoice)->makePartial();
            $invMock->shouldReceive('generatePDF')->once()->andReturn('%PDF-INV');
            $resp = $action->evaluate($action->getActionFunction(), ['record' => $invMock]);
            expect($resp)->toBeInstanceOf(StreamedResponse::class);
            ob_start();
            $resp->sendContent();
            expect(ob_get_clean())->toBe('%PDF-INV');
        }
    }

    // DocumentsRelationManager
    $docServiceMock = Mockery::mock(DocumentService::class);
    $docServiceMock->shouldReceive('attach')->once();
    $docServiceMock->shouldReceive('addVersion')->once();
    app()->instance(DocumentService::class, $docServiceMock);

    $relManager = new DocumentsRelationManager();
    $relManager->ownerRecord = $invoice;
    $docTable = $relManager->table(Table::make($relManager));

    $fakeFile = UploadedFile::fake()->create('receipt.pdf', 10);
    foreach ($docTable->getHeaderActions() as $action) {
        if ($action instanceof Action && $action->getName() === 'attach') {
            $action->evaluate($action->getActionFunction(), ['data' => ['file' => $fakeFile, 'retention_until' => '2030-01-01']]);
            // Non-Invoice owner early return
            $relManager->ownerRecord = $customer;
            $action->evaluate($action->getActionFunction(), ['data' => ['file' => $fakeFile, 'retention_until' => null]]);
            $relManager->ownerRecord = $invoice;
        }
    }

    Storage::disk('public')->put('docs/v1.pdf', 'pdf-bytes');
    $doc = new Document(['disk' => 'public', 'name' => 'v1.pdf']);
    $ver = new DocumentVersion(['path' => 'docs/v1.pdf', 'version_number' => 1]);
    $doc->setRelation('currentVersion', $ver);
    $docWithoutVer = new Document(['disk' => 'public', 'name' => 'missing.pdf']);
    $docWithoutVer->setRelation('currentVersion', null);

    foreach ($docTable->getRecordActions() as $action) {
        if (! $action instanceof Action) {
            continue;
        }
        if ($action->getName() === 'uploadVersion') {
            $action->evaluate($action->getActionFunction(), ['record' => $doc, 'data' => ['file' => $fakeFile]]);
        }
        if ($action->getName() === 'download') {
            expect($action->evaluate($action->getActionFunction(), ['record' => $doc]))->not->toBeNull();
            expect(fn () => $action->evaluate($action->getActionFunction(), ['record' => $docWithoutVer]))
                ->toThrow(HttpException::class);
        }
    }

    // 4. JournalEntries
    $listJe = new ListJournalEntries();
    $createJe = new CreateJournalEntry();
    $editJe = new EditJournalEntry();

    expect(JournalEntryResource::getPages())->toHaveKeys(['index', 'create', 'edit'])
        ->and(Closure::bind(fn () => $this->getHeaderActions(), $listJe, ListJournalEntries::class)())->toHaveCount(1);

    $jeForm = JournalEntryResource::form(Schema::make($createJe)->model(JournalEntry::class));
    $jeForm->fill([
        'lines' => [
            ['account_id' => $invAccount->id, 'debit_amount' => 100, 'credit_amount' => 0],
            ['account_id' => $cogsAccount->id, 'debit_amount' => 0, 'credit_amount' => 100],
        ],
    ]);
    foreach ($jeForm->getFlatComponents(withActions: false, withHidden: true) as $c) {
        if ($c instanceof Placeholder) {
            expect($c->getContent())->not->toBeNull();
        }
        if ($c instanceof Select && $c->getName() === 'account_id') {
            expect($c->getOptions())->toBeArray();
        }
        if (method_exists($c, 'getName') && in_array($c->getName(), ['debit_amount', 'credit_amount'], true)) {
            $c->state(50);
            $c->callAfterStateUpdated();
        }
    }

    $jeTable = JournalEntryResource::table(Table::make($listJe));
    Closure::bind(fn () => $this->table = $jeTable, $listJe, ListJournalEntries::class)();
    /** @var TextColumn $jeTypeCol */
    $jeTypeCol = $jeTable->getColumn('entry_type');
    foreach (['general', 'adjusting', 'closing', 'reversing', 'other'] as $t) {
        expect($jeTypeCol->getColor($t))->toBeString();
    }
    $jeTable->getColumn('total_debits')->record($jeStub)->getState();

    $jePartial = Mockery::mock($jeStub)->makePartial();
    $jePartial->shouldReceive('post')->once()->andReturn($jePartial);
    $jePartial->shouldReceive('post')->once()->andThrow(new Exception('Unbalanced'));
    $jePartial->shouldReceive('reverse')->once()->andReturn($jePartial);
    $jePartial->shouldReceive('reverse')->once()->andThrow(new Exception('Cannot reverse'));

    foreach ($jeTable->getRecordActions() as $action) {
        if (! $action instanceof Action) {
            continue;
        }
        if ($action->getName() === 'post') {
            $action->record($jePartial);
            expect($action->isVisible())->toBeTrue();
            $action->evaluate($action->getActionFunction(), ['record' => $jePartial]);
            $action->evaluate($action->getActionFunction(), ['record' => $jePartial]);
        }
        if ($action->getName() === 'reverse') {
            $jePartial->is_posted = true;
            $action->record($jePartial);
            expect($action->isVisible())->toBeTrue();
            $action->evaluate($action->getActionFunction(), ['record' => $jePartial]);
            $action->evaluate($action->getActionFunction(), ['record' => $jePartial]);
            $jePartial->is_posted = false;
        }
        if ($action->getName() === 'delete') {
            $action->record($jePartial);
            $action->isVisible();
        }
    }

    // CreateJournalEntry & EditJournalEntry methods
    $jeMutated = Closure::bind(fn (array $d) => $this->mutateFormDataBeforeCreate($d), $createJe, CreateJournalEntry::class)([]);
    expect($jeMutated['user_id'])->toBe($user->id);

    $createJe->data = ['lines' => [['debit_amount' => 100, 'credit_amount' => 0]]];
    Closure::bind(fn () => $this->beforeValidate(), $createJe, CreateJournalEntry::class)();
    expect(Closure::bind(fn () => $this->getRedirectUrl(), $createJe, CreateJournalEntry::class)())->toBeString();

    $editJe->record = $jeStub;
    $editActions = Closure::bind(fn () => $this->getHeaderActions(), $editJe, EditJournalEntry::class)();
    expect($editActions)->toHaveCount(1);
    $editActions[0]->record($jeStub)->isVisible();

    $editJe->data = ['lines' => [['debit_amount' => 100, 'credit_amount' => 0]]];
    Closure::bind(fn () => $this->beforeValidate(), $editJe, EditJournalEntry::class)();
    expect(Closure::bind(fn () => $this->getRedirectUrl(), $editJe, EditJournalEntry::class)())->toBeString();

    $jeStub->forceFill(['is_posted' => true])->save();
    $editJe->record = $jeStub;
    try {
        Closure::bind(fn () => $this->beforeValidate(), $editJe, EditJournalEntry::class)();
    } catch (Halt) {
        // Expected halt when editing a posted journal entry
    }
});
