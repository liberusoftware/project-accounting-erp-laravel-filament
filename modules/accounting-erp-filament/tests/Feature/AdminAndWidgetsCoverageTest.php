<?php

declare(strict_types=1);

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Jetstream\Features;
use Liberu\Accounting\Erp\Actions\Jetstream\CreateTeam as CreateTeamAction;
use Liberu\Accounting\Erp\Exceptions\ApprovalDeniedException;
use Liberu\Accounting\Erp\Models\ApprovalRequest;
use Liberu\Accounting\Erp\Models\ApprovalRule;
use Liberu\Accounting\Erp\Models\ApprovalStep;
use Liberu\Accounting\Erp\Models\AuditLog;
use Liberu\Accounting\Erp\Models\ConsolidationGroup;
use Liberu\Accounting\Erp\Models\Employee;
use Liberu\Accounting\Erp\Models\Expense;
use Liberu\Accounting\Erp\Models\ForecastScenario;
use Liberu\Accounting\Erp\Models\Invoice;
use Liberu\Accounting\Erp\Models\Payroll;
use Liberu\Accounting\Erp\Models\Team;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\Erp\Models\UserNotificationPreference;
use Liberu\Accounting\Erp\Models\Vendor;
use Liberu\Accounting\Erp\Notifications\ExpenseApprovalNotification;
use Liberu\Accounting\Erp\Notifications\PortalAccessNotification;
use Liberu\Accounting\Erp\Services\ApprovalService;
use Liberu\Accounting\Erp\Services\ConsolidationService;
use Liberu\Accounting\Erp\Services\ForecastService;
use Liberu\Accounting\Erp\Services\GeneralLedgerService;
use Liberu\Accounting\Erp\Services\PayslipService;
use Liberu\Accounting\Erp\Services\TeamManagementService;
use Liberu\Accounting\Erp\Settings\GeneralSettings;
use Liberu\Accounting\ErpFilament\ErpFilamentPlugin;
use Liberu\Accounting\ErpFilament\ErpFilamentServiceProvider;
use Liberu\Accounting\ErpFilament\Filament\Admin\Pages\ManageGeneralSettings;
use Liberu\Accounting\ErpFilament\Filament\Admin\Pages\PlatformSetupWizard;
use Liberu\Accounting\ErpFilament\Filament\Admin\Resources\AuditLogs\AuditLogResource;
use Liberu\Accounting\ErpFilament\Filament\Admin\Resources\AuditLogs\Pages\CreateAuditLog;
use Liberu\Accounting\ErpFilament\Filament\Admin\Resources\AuditLogs\Pages\ListAuditLogs;
use Liberu\Accounting\ErpFilament\Filament\Admin\Resources\AuditLogs\Pages\ViewAuditLog;
use Liberu\Accounting\ErpFilament\Filament\Admin\Resources\Expenses\ExpenseResource;
use Liberu\Accounting\ErpFilament\Filament\Admin\Resources\Expenses\Pages\CreateExpense;
use Liberu\Accounting\ErpFilament\Filament\Admin\Resources\Expenses\Pages\EditExpense;
use Liberu\Accounting\ErpFilament\Filament\Admin\Resources\Expenses\Pages\ListExpenses;
use Liberu\Accounting\ErpFilament\Filament\Admin\Resources\MenuResource;
use Liberu\Accounting\ErpFilament\Filament\Admin\Resources\MenuResource\Pages\CreateMenu;
use Liberu\Accounting\ErpFilament\Filament\Admin\Resources\MenuResource\Pages\EditMenu;
use Liberu\Accounting\ErpFilament\Filament\Admin\Resources\MenuResource\Pages\ListMenus;
use Liberu\Accounting\ErpFilament\Filament\Admin\Resources\Payrolls\Pages\CreatePayroll;
use Liberu\Accounting\ErpFilament\Filament\Admin\Resources\Payrolls\Pages\EditPayroll;
use Liberu\Accounting\ErpFilament\Filament\Admin\Resources\Payrolls\Pages\ListPayrolls;
use Liberu\Accounting\ErpFilament\Filament\Admin\Resources\Payrolls\PayrollResource;
use Liberu\Accounting\ErpFilament\Filament\Admin\Resources\Vendors\Pages\CreateVendor;
use Liberu\Accounting\ErpFilament\Filament\Admin\Resources\Vendors\Pages\EditVendor;
use Liberu\Accounting\ErpFilament\Filament\Admin\Resources\Vendors\Pages\ListVendors;
use Liberu\Accounting\ErpFilament\Filament\Admin\Resources\Vendors\VendorResource;
use Liberu\Accounting\ErpFilament\Filament\App\Pages\AccountSetupWizard;
use Liberu\Accounting\ErpFilament\Filament\App\Pages\ConsolidatedReports;
use Liberu\Accounting\ErpFilament\Filament\App\Pages\CreateTeam;
use Liberu\Accounting\ErpFilament\Filament\App\Pages\EditProfile;
use Liberu\Accounting\ErpFilament\Filament\App\Pages\EditTeam;
use Liberu\Accounting\ErpFilament\Filament\App\Pages\ForecastComparison;
use Liberu\Accounting\ErpFilament\Filament\App\Pages\NotificationSettings;
use Liberu\Accounting\ErpFilament\Filament\App\Pages\PendingApprovals;
use Liberu\Accounting\ErpFilament\Filament\App\Pages\PersonalAccessTokensPage;
use Liberu\Accounting\ErpFilament\Filament\App\Pages\TeamNotificationSettings;
use Liberu\Accounting\ErpFilament\Filament\App\Pages\UpdateProfileInformationPage;
use Liberu\Accounting\ErpFilament\Filament\App\Widgets\AccountingQuickActions;
use Liberu\Accounting\ErpFilament\Filament\App\Widgets\SocialLinksWidget;
use Liberu\Accounting\ErpFilament\Filament\Widgets\FinancialDashboard;
use Liberu\Accounting\ErpFilament\Filament\Widgets\PlatformSetup;
use Liberu\Accounting\ErpFilament\Http\Middleware\EnsureTwoFactorEnabled;
use Liberu\Accounting\ErpFilament\View\Components\AppLayout;
use Liberu\Accounting\ErpFilament\View\Components\GuestLayout;
use Liberu\Foundation\RolesPermissions\Models\Role;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\StreamedResponse;

beforeEach(function (): void {
    Http::fake();
    Mail::fake();
    Queue::fake();
    Storage::fake('public');
});

/**
 * Helper to create a user + personal team + super_admin role for admin/app tests.
 *
 * @return array{user: User, team: Team}
 */
function erpPart1AdminActor(bool $superAdmin = true): array
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $team = app(TeamManagementService::class)->createPersonalTeamForUser($user);
    $team->forceFill(['accounting_setup_completed_at' => now()])->save();
    $user->unsetRelation('currentTeam');

    if ($superAdmin) {
        setPermissionsTeamId($team->id);
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web', 'team_id' => $team->id]);
        $user->assignRole('super_admin');
    }

    return ['user' => $user, 'team' => $team];
}

it('registers ErpFilamentPlugin and ErpFilamentServiceProvider across panels', function (): void {
    $provider = $this->app->register(ErpFilamentServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(ErpFilamentServiceProvider::class);

    $plugin = ErpFilamentPlugin::make();
    expect($plugin->getId())->toBe('accounting-erp');

    foreach (['admin', 'app', 'other'] as $panelId) {
        $panel = Panel::make()->id($panelId);
        $plugin->register($panel);
        $plugin->boot($panel);
    }
});

it('renders AppLayout and GuestLayout view components', function (): void {
    $appView = (new AppLayout())->render();
    $guestView = (new GuestLayout())->render();

    expect($appView)->toBeInstanceOf(View::class)
        ->and($appView->name())->toBe('layouts.app')
        ->and($guestView)->toBeInstanceOf(View::class)
        ->and($guestView->name())->toBe('layouts.guest');
});

it('covers all branches of EnsureTwoFactorEnabled middleware', function (): void {
    $middleware = new EnsureTwoFactorEnabled();
    $next = static fn (Request $req) => response('ok');

    // 1. Unauthenticated passes through
    $this->app['auth']->logout();
    expect($middleware->handle(Request::create('/app'), $next)->getContent())->toBe('ok');

    // 2. Non-privileged user passes through
    ['user' => $regularUser, 'team' => $regularTeam] = erpPart1AdminActor(false);
    $this->actingAs($regularUser);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($regularTeam);
    expect($middleware->handle(Request::create('/app'), $next)->getContent())->toBe('ok');

    // 3. Privileged user with enforce_2fa disabled passes through
    ['user' => $admin, 'team' => $team] = erpPart1AdminActor(true);
    $this->actingAs($admin);
    config(['accounting.enforce_2fa' => false]);
    expect($middleware->handle(Request::create('/app'), $next)->getContent())->toBe('ok');

    // 4. Privileged user with enforce_2fa enabled and 2FA already enrolled passes through
    config(['accounting.enforce_2fa' => true]);
    $admin->forceFill([
        'two_factor_secret' => encrypt('SECRETKEY'),
        'two_factor_confirmed_at' => now(),
    ])->save();
    expect($middleware->handle(Request::create('/app'), $next)->getContent())->toBe('ok');

    // 5. Privileged user without 2FA on an allowed route passes through
    $admin->forceFill([
        'two_factor_secret' => null,
        'two_factor_confirmed_at' => null,
    ])->save();

    $allowedRequest = Request::create('/two-factor');
    $allowedRoute = new Route('GET', '/two-factor', fn () => 'ok');
    $allowedRoute->name('two-factor.enable');
    $allowedRequest->setRouteResolver(fn () => $allowedRoute);
    expect($middleware->handle($allowedRequest, $next)->getContent())->toBe('ok');

    // 6. Privileged user without 2FA on a disallowed route with team redirects to EditProfile
    $blockedRequest = Request::create('/app/invoices');
    $blockedRoute = new Route('GET', '/app/invoices', fn () => 'ok');
    $blockedRoute->name('filament.app.resources.invoices.index');
    $blockedRequest->setRouteResolver(fn () => $blockedRoute);
    $redirectResponse = $middleware->handle($blockedRequest, $next);
    expect($redirectResponse->getStatusCode())->toBe(302);

    // 7. Request with null route name also triggers redirect (hits $name === null branch)
    $noRouteRequest = Request::create('/app/no-route');
    expect($middleware->handle($noRouteRequest, $next)->getStatusCode())->toBe(302);

    // 8. Privileged user on tenant panel with no currentTeam passes through
    $originalPanel = Filament::getCurrentPanel();
    Filament::setCurrentPanel(Panel::make()->id('app')->tenant(Team::class));
    $admin->forceFill(['current_team_id' => null])->save();
    $admin->setRelation('currentTeam', null);
    expect($middleware->handle($noRouteRequest, $next)->getContent())->toBe('ok');
    Filament::setCurrentPanel($originalPanel);
});

it('covers AccountingQuickActions, SocialLinksWidget, FinancialDashboard, and PlatformSetup widgets', function (): void {
    // AccountingQuickActions with no tenant or user
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant(null);
    $this->app['auth']->logout();
    $quickActions = new AccountingQuickActions();
    expect($quickActions->workspace())->toBeNull()
        ->and($quickActions->actions())->toBeArray();

    // AccountingQuickActions with authenticated owner + configured and unconfigured fiscal_year_start
    ['user' => $user, 'team' => $team] = erpPart1AdminActor(true);
    $this->actingAs($user);
    Filament::setTenant($team);

    $team->update([
        'accounting_setup' => ['currency' => 'GBP', 'fiscal_year_start' => '04-01'],
        'accounting_setup_completed_at' => now(),
    ]);
    $ws = $quickActions->workspace();
    expect($ws)->toMatchArray(['currency' => 'GBP', 'fiscal_year_start' => '1 April', 'completed' => true]);

    $team->update([
        'accounting_setup' => ['currency' => 'USD', 'fiscal_year_start' => 'invalid'],
        'accounting_setup_completed_at' => null,
    ]);
    $ws2 = $quickActions->workspace();
    expect($ws2)->toMatchArray(['currency' => 'USD', 'fiscal_year_start' => 'Not configured', 'completed' => false]);

    $actions = $quickActions->actions();
    expect($actions)->toBeArray()->not->toBeEmpty();

    // SocialLinksWidget with all 4 links populated
    $settings = Mockery::mock(GeneralSettings::class)->makePartial();
    $settings->github_url = 'https://github.com/liberu';
    $settings->facebook_url = 'https://facebook.com/liberu';
    $settings->twitter_url = 'https://twitter.com/liberu';
    $settings->youtube_url = 'https://youtube.com/liberu';
    app()->instance(GeneralSettings::class, $settings);

    $socialView = (new SocialLinksWidget())->render();
    expect($socialView)->toBeInstanceOf(View::class)
        ->and($socialView->getData()['links'])->toHaveKeys(['GitHub', 'Facebook', 'Twitter', 'YouTube']);

    // FinancialDashboard positive and negative net income
    $glMock = Mockery::mock(GeneralLedgerService::class);
    $glMock->shouldReceive('getKeyMetrics')->once()->andReturn([
        'revenue' => 1000.0,
        'expenses' => 400.0,
        'netIncome' => 600.0,
        'revenueChart' => [100.0, 200.0],
        'expensesChart' => [50.0, 100.0],
        'netIncomeChart' => [50.0, 100.0],
    ]);
    $glMock->shouldReceive('getKeyMetrics')->once()->andReturn([
        'revenue' => 200.0,
        'expenses' => 500.0,
        'netIncome' => -300.0,
        'revenueChart' => [100.0],
        'expensesChart' => [250.0],
        'netIncomeChart' => [-150.0],
    ]);
    app()->instance(GeneralLedgerService::class, $glMock);

    $dashboard = new FinancialDashboard();
    $statsPositive = Closure::bind(fn (): array => $this->getStats(), $dashboard, FinancialDashboard::class)();
    $statsNegative = Closure::bind(fn (): array => $this->getStats(), $dashboard, FinancialDashboard::class)();
    expect($statsPositive)->toHaveCount(3)
        ->and($statsNegative)->toHaveCount(3);

    // PlatformSetup widget canView
    expect(PlatformSetup::canView())->toBeTrue();
    $this->app['auth']->logout();
    expect(PlatformSetup::canView())->toBeFalse();
});

it('covers ManageGeneralSettings and PlatformSetupWizard admin pages', function (): void {
    ['user' => $user, 'team' => $team] = erpPart1AdminActor(true);
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($team);

    $generalPage = new ManageGeneralSettings();
    expect(ManageGeneralSettings::canAccess())->toBeTrue()
        ->and($generalPage->canEdit())->toBeTrue()
        ->and($generalPage->form(Schema::make($generalPage)))->toBeInstanceOf(Schema::class);

    $wizardPage = new PlatformSetupWizard();
    expect($wizardPage->getSubheading())->toBeString()->not->toBeEmpty()
        ->and($wizardPage->getFormActions())->toBe([])
        ->and($wizardPage->getSavedNotificationTitle())->toBe('Platform settings saved');

    $wizardSchema = $wizardPage->form(Schema::make($wizardPage));
    expect($wizardSchema)->toBeInstanceOf(Schema::class);

    // Evaluate all Text closures in the Review & save step
    $wizardPage->data = [
        'site_name' => 'Liberu ERP',
        'site_email' => 'ops@example.com',
        'site_country' => 'UK',
        'site_currency' => '£',
        'site_default_language' => 'en',
        'site_phone' => '',
        'site_address' => '',
        'footer_copyright' => 'Liberu',
    ];
    foreach ($wizardSchema->getFlatComponents(withActions: false, withHidden: true) as $component) {
        if ($component instanceof Text) {
            expect($component->getContent())->not->toBeNull();
        }
    }
});

it('covers Admin resources: AuditLogs, Expenses, MenuResource, Payrolls, and Vendors', function (): void {
    Notification::fake();

    ['user' => $user, 'team' => $team] = erpPart1AdminActor(true);
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($team);

    // 1. AuditLogResource + Pages
    $listAuditHost = new ListAuditLogs();
    $createAuditHost = new CreateAuditLog();
    $viewAuditHost = new ViewAuditLog();
    expect($createAuditHost)->toBeInstanceOf(CreateAuditLog::class)
        ->and($viewAuditHost)->toBeInstanceOf(ViewAuditLog::class)
        ->and(AuditLogResource::getEloquentQuery())->toBeInstanceOf(Builder::class)
        ->and(AuditLogResource::getPages())->toHaveKeys(['index', 'view']);

    $auditTable = AuditLogResource::table(Table::make($listAuditHost));
    /** @var TextColumn $eventCol */
    $eventCol = $auditTable->getColumn('event');
    foreach (['created', 'updated', 'deleted', 'custom'] as $state) {
        expect($eventCol->getColor($state))->toBeString();
    }
    /** @var TextColumn $typeCol */
    $typeCol = $auditTable->getColumn('auditable_type');
    expect($typeCol->formatState(Invoice::class))->toBe('Invoice');

    $dateFilter = $auditTable->getFilter('created_at');
    $dateFilter->apply(AuditLog::query(), ['from' => '2026-01-01', 'until' => '2026-12-31']);

    // 2. ExpenseResource + Pages
    $listExpHost = new ListExpenses();
    $editExpHost = new EditExpense();
    $createExpHost = new CreateExpense();
    expect($createExpHost)->toBeInstanceOf(CreateExpense::class)
        ->and(ExpenseResource::getRelations())->toBe([])
        ->and(ExpenseResource::getPages())->toHaveKeys(['index', 'create', 'edit'])
        ->and(ExpenseResource::getNavigationBadge())->toBeNull()
        ->and(Closure::bind(fn () => $this->getHeaderActions(), $listExpHost, ListExpenses::class)())->toHaveCount(1)
        ->and(Closure::bind(fn () => $this->getHeaderActions(), $editExpHost, EditExpense::class)())->toHaveCount(1);

    $expense = Expense::forceCreate([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'amount' => 125.50,
        'description' => 'Office supplies',
        'date' => now()->toDateString(),
        'approval_status' => 'pending',
    ]);
    expect(ExpenseResource::getNavigationBadge())->toBe('1');

    $expForm = ExpenseResource::form(Schema::make($createExpHost));
    expect($expForm)->toBeInstanceOf(Schema::class);
    $expForm->model($expense)->fill([
        'is_recurring' => true,
        'recurrence_start' => '2026-01-01',
    ]);
    foreach ($expForm->getFlatComponents(withActions: false, withHidden: true) as $component) {
        $component->isVisible();
        if (method_exists($component, 'isRequired')) {
            $component->isRequired();
        }
        if (method_exists($component, 'getMinDate')) {
            $component->getMinDate();
        }
    }

    $expTable = ExpenseResource::table(Table::make($listExpHost));
    /** @var TextColumn $statusCol */
    $statusCol = $expTable->getColumn('approval_status');
    foreach (['approved', 'rejected', 'pending'] as $st) {
        expect($statusCol->getColor($st))->toBeString();
    }
    $expTable->getColumn('approver.name')->record($expense)->isVisible();

    foreach ($expTable->getRecordActions() as $action) {
        if (! $action instanceof Action) {
            continue;
        }
        if ($action->getName() === 'approve') {
            $action->record($expense);
            expect($action->isVisible())->toBeTrue();
            $action->evaluate($action->getActionFunction(), ['record' => $expense]);
        }
        if ($action->getName() === 'reject') {
            $expense2 = Expense::forceCreate([
                'team_id' => $team->id,
                'user_id' => $user->id,
                'amount' => 50.00,
                'description' => 'Meals',
                'date' => now()->toDateString(),
                'approval_status' => 'pending',
            ]);
            $action->record($expense2);
            expect($action->isVisible())->toBeTrue();
            $action->evaluate($action->getActionFunction(), ['record' => $expense2, 'data' => ['reason' => 'Missing receipt']]);
        }
    }
    Notification::assertSentTo($user, ExpenseApprovalNotification::class);

    // 3. MenuResource + Pages
    $listMenuHost = new ListMenus();
    expect(new CreateMenu())->toBeInstanceOf(CreateMenu::class)
        ->and(new EditMenu())->toBeInstanceOf(EditMenu::class)
        ->and(MenuResource::form(Schema::make($listMenuHost)))->toBeInstanceOf(Schema::class)
        ->and(MenuResource::table(Table::make($listMenuHost)))->toBeInstanceOf(Table::class)
        ->and(MenuResource::getPages())->toHaveKeys(['index', 'create', 'edit']);

    // 4. PayrollResource + Pages
    $listPayrollHost = new ListPayrolls();
    $editPayrollHost = new EditPayroll();
    expect(new CreatePayroll())->toBeInstanceOf(CreatePayroll::class)
        ->and(PayrollResource::form(Schema::make($listPayrollHost)))->toBeInstanceOf(Schema::class)
        ->and(PayrollResource::getPages())->toHaveKeys(['index', 'create', 'edit'])
        ->and(Closure::bind(fn () => $this->getHeaderActions(), $listPayrollHost, ListPayrolls::class)())->toHaveCount(1)
        ->and(Closure::bind(fn () => $this->getHeaderActions(), $editPayrollHost, EditPayroll::class)())->toHaveCount(1);

    $payslipMock = Mockery::mock(PayslipService::class);
    $payslipMock->shouldReceive('pdf')->once()->andReturn('%PDF-1.4 mock');
    app()->instance(PayslipService::class, $payslipMock);

    $employee = Employee::forceCreate([
        'team_id' => $team->id,
        'name' => 'Test Employee',
        'email' => 'employee@example.com',
        'position' => 'Accountant',
        'hire_date' => '2025-01-01',
        'tax_id' => 'TAX-12345',
    ]);

    $payroll = Payroll::forceCreate([
        'team_id' => $team->id,
        'employee_id' => $employee->id,
        'base_salary' => 5000,
        'overtime_hours' => 0,
        'overtime_rate' => 0,
        'tax_deductions' => 500,
        'other_deductions' => 0,
        'net_salary' => 4500,
        'pay_period_start' => '2026-01-01',
        'pay_period_end' => '2026-01-31',
        'payment_date' => '2026-01-31',
        'payment_status' => 'paid',
    ]);

    $payrollTable = PayrollResource::table(Table::make($listPayrollHost));
    foreach ($payrollTable->getRecordActions() as $action) {
        if ($action instanceof Action && $action->getName() === 'downloadPayslip') {
            $response = $action->evaluate($action->getActionFunction(), ['record' => $payroll]);
            expect($response)->toBeInstanceOf(StreamedResponse::class);
            ob_start();
            $response->sendContent();
            $content = ob_get_clean();
            expect($content)->toBe('%PDF-1.4 mock');
        }
    }

    // 5. VendorResource + Pages
    $listVendorHost = new ListVendors();
    $editVendorHost = new EditVendor();
    expect(new CreateVendor())->toBeInstanceOf(CreateVendor::class)
        ->and(VendorResource::form(Schema::make($listVendorHost)))->toBeInstanceOf(Schema::class)
        ->and(VendorResource::getPages())->toHaveKeys(['index', 'create', 'edit'])
        ->and(Closure::bind(fn () => $this->getHeaderActions(), $listVendorHost, ListVendors::class)())->toHaveCount(1)
        ->and(Closure::bind(fn () => $this->getHeaderActions(), $editVendorHost, EditVendor::class)())->toHaveCount(1);

    $vendor = Vendor::forceCreate([
        'team_id' => $team->id,
        'name' => 'Acme Vendor',
        'email' => 'vendor@example.com',
        'status' => 'active',
    ]);
    $vendorTable = VendorResource::table(Table::make($listVendorHost));
    foreach ($vendorTable->getRecordActions() as $action) {
        if ($action instanceof Action && $action->getName() === 'sendPortalInvite') {
            $action->record($vendor);
            expect($action->isVisible())->toBeTrue();
            $action->evaluate($action->getActionFunction(), ['record' => $vendor]);
        }
    }
    Notification::assertSentTo($vendor, PortalAccessNotification::class);
});

it('covers App Pages: AccountSetupWizard, ConsolidatedReports, CreateTeam, EditProfile, EditTeam', function (): void {
    ['user' => $user, 'team' => $team] = erpPart1AdminActor(true);
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($team);

    // 1. AccountSetupWizard (including vonage_key, vonage_secret, integrations, content(), getSubheading())
    $team->update([
        'accounting_setup' => [
            'integrations' => ['xero' => ['client_secret' => 'existing-secret']],
        ],
    ]);
    $wizard = new AccountSetupWizard();
    expect($wizard->getSubheading())->toBeString()
        ->and($wizard->content(Schema::make($wizard)))->toBeInstanceOf(Schema::class);

    Livewire::test(AccountSetupWizard::class)
        ->fillForm([
            'business_name' => 'Coverage Co',
            'country' => 'GB',
            'currency' => 'GBP',
            'fiscal_year_start' => '04-01',
            'timezone' => 'Europe/London',
            'vonage_key' => 'v-key',
            'vonage_secret' => 'v-secret',
            'vonage_from' => 'Coverage',
            'integrations' => [
                'plaid' => ['client_id' => 'plaid-id', 'secret' => 'plaid-sec'],
                'xero' => ['client_id' => 'xero-id', 'client_secret' => ''],
            ],
        ])
        ->call('save')
        ->assertRedirect('/app');

    $team->refresh();
    expect($team->name)->toBe('Coverage Co')
        ->and($team->accounting_setup['integrations']['plaid']['client_id'])->toBe('plaid-id')
        ->and($team->accounting_setup['integrations']['xero']['client_secret'])->toBe('existing-secret');

    // 2. ConsolidatedReports
    $group = ConsolidationGroup::create(['name' => 'Holding Group', 'owner_team_id' => $team->id]);
    $group->members()->attach($team->id);

    $pnlData = [
        'consolidated' => ['revenue' => 200.0, 'expenses' => 100.0, 'net_income' => 100.0],
        'eliminations' => ['revenue' => 0.0],
    ];
    $bsData = [
        'consolidated' => ['assets' => 500.0, 'liabilities' => 200.0, 'equity' => 300.0],
    ];
    $cfData = [
        'consolidated' => ['net_change_in_cash' => 50.0, 'ending_cash' => 150.0],
    ];

    $consolidationMock = Mockery::mock(ConsolidationService::class);
    $consolidationMock->shouldReceive('consolidatedProfitAndLoss')->once()->andReturn($pnlData);
    $consolidationMock->shouldReceive('consolidatedBalanceSheet')->once()->andReturn($bsData);
    $consolidationMock->shouldReceive('consolidatedCashFlow')->once()->andReturn($cfData);
    app()->instance(ConsolidationService::class, $consolidationMock);

    $reportsPage = new ConsolidatedReports();
    expect($reportsPage->visibleGroups())->toHaveKey($group->id)
        ->and($reportsPage->form(Schema::make($reportsPage)))->toBeInstanceOf(Schema::class)
        ->and($reportsPage->content(Schema::make($reportsPage)))->toBeInstanceOf(Schema::class);

    // Unauthorized group early return
    $reportsPage->data = ['consolidation_group_id' => 999999, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'];
    $reportsPage->generate();
    expect($reportsPage->profitAndLoss)->toBeNull();

    // Authorized group via Livewire (covers mount + generate)
    Livewire::test(ConsolidatedReports::class)
        ->fillForm([
            'consolidation_group_id' => $group->id,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ])
        ->call('generate')
        ->assertSet('profitAndLoss', $pnlData)
        ->assertSet('balanceSheet', $bsData)
        ->assertSet('cashFlow', $cfData);

    // 3. CreateTeam
    $createTeamPage = new CreateTeam();
    $createTeamPage->mount();
    expect(CreateTeam::getLabel())->toBe('Create Team')
        ->and($createTeamPage->getBreadcrumbs())->toBeArray()
        ->and($createTeamPage->form(Schema::make($createTeamPage)))->toBeInstanceOf(Schema::class);

    $createTeamMock = Mockery::mock(CreateTeamAction::class);
    $createTeamMock->shouldReceive('create')->once()->andReturn($team);
    app()->instance(CreateTeamAction::class, $createTeamMock);
    $created = Closure::bind(fn () => $this->handleRegistration(['name' => 'New Team']), $createTeamPage, CreateTeam::class)();
    expect($created->getKey())->toBe($team->getKey());

    // 4. EditProfile (mount, form, submit, enableTwoFactor, confirmTwoFactor success + catch, disableTwoFactor, breadcrumbs)
    $enableMock = Mockery::mock(EnableTwoFactorAuthentication::class);
    $enableMock->shouldReceive('__invoke')->once();
    $confirmMock = Mockery::mock(ConfirmTwoFactorAuthentication::class);
    $confirmMock->shouldReceive('__invoke')->once()->with(Mockery::type(User::class), '000000')->andThrow(ValidationException::withMessages(['code' => 'Invalid']));
    $confirmMock->shouldReceive('__invoke')->once()->with(Mockery::type(User::class), '123456');
    $disableMock = Mockery::mock(DisableTwoFactorAuthentication::class);
    $disableMock->shouldReceive('__invoke')->once();

    $editProfile = new EditProfile();
    expect($editProfile->getBreadcrumbs())->toBeArray();

    Livewire::test(EditProfile::class)
        ->fillForm(['name' => 'Updated Admin', 'email' => $user->email])
        ->call('submit');
    expect($user->fresh()->name)->toBe('Updated Admin');

    $editProfile->user = $user->fresh();
    $editProfile->enableTwoFactor($enableMock);
    $editProfile->twoFactorCode = '000000';
    $editProfile->confirmTwoFactor($confirmMock);
    $editProfile->twoFactorCode = '123456';
    $editProfile->confirmTwoFactor($confirmMock);
    $editProfile->disableTwoFactor($disableMock);

    // 5. EditTeam
    config(['jetstream.features' => [...config('jetstream.features', []), Features::teams()]]);
    RouteFacade::get('/test-edit-team/{team}', fn () => 'ok')->name('filament.pages.edit-team');
    RouteFacade::getRoutes()->refreshNameLookups();
    Gate::before(fn () => true);
    $editTeamPage = new EditTeam();
    $editTeamPage->mount();
    expect(EditTeam::getLabel())->toBe('Edit Team')
        ->and($editTeamPage->getBreadcrumbs())->toBeArray()
        ->and($editTeamPage->form(Schema::make($editTeamPage)))->toBeInstanceOf(Schema::class);

    $editTeamPage->data = ['name' => 'Second Team'];
    Closure::bind(
        fn () => $this->cacheSchema('form', $this->form(Schema::make($this)->statePath('data'))),
        $editTeamPage,
        EditTeam::class
    )();
    $editTeamPage->submit();
    expect(Team::where('name', 'Second Team')->exists())->toBeTrue();
});

it('covers App Pages: ForecastComparison, NotificationSettings, PendingApprovals, PersonalAccessTokensPage, TeamNotificationSettings, UpdateProfileInformationPage', function (): void {
    ['user' => $user, 'team' => $team] = erpPart1AdminActor(true);
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($team);

    // 1. ForecastComparison
    $scenario = ForecastScenario::create(['name' => 'Optimistic', 'team_id' => $team->id]);
    $forecastResult = [
        'baseline_net_income' => ['2026-01' => 100.0, '2026-02' => 120.0],
        'scenario_net_income' => ['2026-01' => 150.0, '2026-02' => 180.0],
    ];
    $forecastMock = Mockery::mock(ForecastService::class);
    $forecastMock->shouldReceive('compare')->once()->andReturn($forecastResult);
    app()->instance(ForecastService::class, $forecastMock);

    $forecastPage = new ForecastComparison();
    expect($forecastPage->visibleScenarios())->toHaveKey($scenario->id)
        ->and($forecastPage->form(Schema::make($forecastPage)))->toBeInstanceOf(Schema::class)
        ->and($forecastPage->content(Schema::make($forecastPage)))->toBeInstanceOf(Schema::class);

    // Unauthorized scenario early return
    $forecastPage->data = ['forecast_scenario_id' => 999999, 'periods' => 3];
    $forecastPage->generate();
    expect($forecastPage->result)->toBeNull();

    Livewire::test(ForecastComparison::class)
        ->fillForm(['forecast_scenario_id' => $scenario->id, 'periods' => 3])
        ->call('generate')
        ->assertSet('result', $forecastResult);

    // 2. NotificationSettings
    $notifPage = new NotificationSettings();
    expect($notifPage->content(Schema::make($notifPage)))->toBeInstanceOf(Schema::class);

    Livewire::test(NotificationSettings::class)
        ->fillForm([
            'phone' => '+447700900000',
            'mail_enabled' => true,
            'database_enabled' => true,
            'sms_enabled' => true,
        ])
        ->call('save');

    expect(UserNotificationPreference::where('user_id', $user->id)->first())
        ->not->toBeNull()
        ->and(UserNotificationPreference::where('user_id', $user->id)->first()->sms_enabled)->toBeTrue();

    // 3. PendingApprovals
    $rule = ApprovalRule::create([
        'team_id' => $team->id,
        'approvable_type' => 'Invoice',
        'min_amount' => 0,
        'steps' => ['super_admin'],
        'is_active' => true,
    ]);
    $request = ApprovalRequest::create([
        'team_id' => $team->id,
        'approvable_type' => Invoice::class,
        'approvable_id' => 42,
        'rule_id' => $rule->id,
        'status' => 'pending',
        'current_step' => 1,
    ]);
    $step = ApprovalStep::create([
        'approval_request_id' => $request->id,
        'position' => 1,
        'role' => 'super_admin',
        'status' => ApprovalStep::STATUS_PENDING,
    ]);

    $approvalMock = Mockery::mock(ApprovalService::class);
    $approvalMock->shouldReceive('canAct')->andReturn(true);
    $approvalMock->shouldReceive('approve')->once()->andReturn($step);
    $approvalMock->shouldReceive('reject')->once()->andThrow(new ApprovalDeniedException('Denied'));
    app()->instance(ApprovalService::class, $approvalMock);

    $pendingPage = new PendingApprovals();
    expect($pendingPage->actionableStepsQuery()->count())->toBe(1);

    $pendingTable = $pendingPage->table(Table::make($pendingPage));
    Closure::bind(fn () => $this->table = $pendingTable, $pendingPage, PendingApprovals::class)();
    /** @var TextColumn $docCol */
    $docCol = $pendingTable->getColumn('document');
    expect($docCol->record($step)->getState())->toBe('Invoice #42');
    $orphanStep = new ApprovalStep();
    $orphanStep->setRelation('request', null);
    expect($docCol->record($orphanStep)->getState())->toBe('—');

    foreach ($pendingTable->getRecordActions() as $action) {
        if ($action instanceof Action && $action->getName() === 'approve') {
            $action->evaluate($action->getActionFunction(), ['record' => $step]);
        }
        if ($action instanceof Action && $action->getName() === 'reject') {
            $action->evaluate($action->getActionFunction(), ['record' => $step, 'data' => ['reason' => 'No budget']]);
        }
    }

    // 4. PersonalAccessTokensPage
    $tokensPage = new PersonalAccessTokensPage();
    $tokensPage->mount();
    expect($tokensPage->getHeading())->toBe('Personal Access Tokens')
        ->and(PersonalAccessTokensPage::shouldRegisterNavigation())->toBeTrue();
    $tokensPage->createApiToken('ci-token');
    expect($user->tokens()->where('name', 'ci-token')->exists())->toBeTrue();
    $tokensPage->deleteApiToken('ci-token');
    expect($user->tokens()->where('name', 'ci-token')->exists())->toBeFalse();

    // 5. TeamNotificationSettings
    $teamNotifPage = new TeamNotificationSettings();
    expect(TeamNotificationSettings::canAccess())->toBeTrue()
        ->and($teamNotifPage->content(Schema::make($teamNotifPage)))->toBeInstanceOf(Schema::class);

    Livewire::test(TeamNotificationSettings::class)
        ->fillForm([
            'vonage_key' => 'key-123',
            'vonage_secret' => 'sec-456',
            'vonage_from' => 'LiberuSMS',
        ])
        ->call('save');
    expect($team->fresh()->vonage_from)->toBe('LiberuSMS');

    // Early return when tenant is null
    Filament::setTenant(null);
    expect(TeamNotificationSettings::canAccess())->toBeFalse();
    $teamNotifPage->save();
    Filament::setTenant($team);

    // 6. UpdateProfileInformationPage
    $profilePage = new UpdateProfileInformationPage();
    expect($profilePage->getHeading())->toBe('Profile')
        ->and(UpdateProfileInformationPage::shouldRegisterNavigation())->toBeTrue();

    Livewire::test(UpdateProfileInformationPage::class)
        ->fillForm(['name' => 'Profile Name', 'email' => $user->email])
        ->call('submit');
    expect($user->fresh()->name)->toBe('Profile Name');
});
