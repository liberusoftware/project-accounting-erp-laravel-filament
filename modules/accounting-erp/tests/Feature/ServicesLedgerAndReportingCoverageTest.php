<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Liberu\Accounting\Erp\Models\Account;
use Liberu\Accounting\Erp\Models\BankStatement;
use Liberu\Accounting\Erp\Models\Budget;
use Liberu\Accounting\Erp\Models\ConsolidationGroup;
use Liberu\Accounting\Erp\Models\Currency;
use Liberu\Accounting\Erp\Models\Customer;
use Liberu\Accounting\Erp\Models\Expense;
use Liberu\Accounting\Erp\Models\ForecastScenario;
use Liberu\Accounting\Erp\Models\Invoice;
use Liberu\Accounting\Erp\Models\JournalEntry;
use Liberu\Accounting\Erp\Models\Project;
use Liberu\Accounting\Erp\Models\ReconciliationRule;
use Liberu\Accounting\Erp\Models\Team;
use Liberu\Accounting\Erp\Models\Transaction;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\Erp\Services\AccountCsvService;
use Liberu\Accounting\Erp\Services\AgingReportService;
use Liberu\Accounting\Erp\Services\BudgetService;
use Liberu\Accounting\Erp\Services\ConsolidationService;
use Liberu\Accounting\Erp\Services\ExchangeRateService;
use Liberu\Accounting\Erp\Services\FinancialStatementService;
use Liberu\Accounting\Erp\Services\ForecastService;
use Liberu\Accounting\Erp\Services\FxRevaluationService;
use Liberu\Accounting\Erp\Services\GeneralLedgerService;
use Liberu\Accounting\Erp\Services\ProjectReportService;
use Liberu\Accounting\Erp\Services\ReconciliationService;
use Liberu\Accounting\Erp\Services\RevenueRecognitionService;

describe('GeneralLedgerService', function (): void {
    it('computes account balances, reporting currency, trial balance, budget comparison, and key metrics', function (): void {
        $user = User::factory()->create();
        $team = Team::factory()->create(['user_id' => $user->id]);
        $user->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($user);

        $usd = Currency::factory()->create(['code' => 'USD', 'is_default' => true]);
        $eur = Currency::factory()->create(['code' => 'EUR', 'is_default' => false]);

        $revAccount = Account::factory()->create([
            'team_id' => $team->id,
            'account_type' => 'revenue',
            'currency_id' => $usd->currency_id,
            'balance' => 500,
        ]);
        $expAccount = Account::factory()->create([
            'team_id' => $team->id,
            'account_type' => 'expense',
            'currency_id' => $usd->currency_id,
            'balance' => -120,
        ]);

        // Current month & previous month transactions for key metrics
        Transaction::factory()->create([
            'team_id' => $team->id,
            'account_id' => $revAccount->id,
            'transaction_date' => now()->toDateString(),
            'amount' => 1000,
        ]);
        Transaction::factory()->create([
            'team_id' => $team->id,
            'account_id' => $revAccount->id,
            'transaction_date' => now()->subMonth()->startOfMonth()->addDay()->toDateString(),
            'amount' => 500,
        ]);
        Transaction::factory()->create([
            'team_id' => $team->id,
            'account_id' => $expAccount->id,
            'transaction_date' => now()->toDateString(),
            'amount' => 300,
        ]);
        Transaction::factory()->create([
            'team_id' => $team->id,
            'account_id' => $expAccount->id,
            'transaction_date' => now()->subMonth()->startOfMonth()->addDay()->toDateString(),
            'amount' => 200,
        ]);

        $service = app(GeneralLedgerService::class);

        config()->set('accounting.reporting_currency', 'EUR');
        expect($service->reportingCurrency()?->code)->toBe('EUR');

        config()->set('accounting.reporting_currency', 'ZZZ');
        expect($service->reportingCurrency()?->code)->toBe('USD');

        $balances = $service->getAccountBalances(now()->subMonth()->toDateString(), now()->toDateString());
        expect($balances)->toHaveCount(2);

        $trial = $service->getTrialBalance(now()->toDateString());
        expect($trial)->toHaveCount(2);

        // Also cover null displayCurrency fallback when no default currency exists
        Currency::query()->update(['is_default' => false]);
        expect($service->getAccountBalances(now()->subMonth()->toDateString(), now()->toDateString()))->toHaveCount(2);
        expect($service->getTrialBalance(now()->toDateString()))->toHaveCount(2);

        $comparison = $service->getBudgetComparison(now()->startOfMonth(), now()->endOfMonth());
        expect($comparison)->toBeIterable();

        $metrics = $service->getKeyMetrics();
        expect($metrics['revenue'])->toBeGreaterThanOrEqual(1000)
            ->and($metrics['previousRevenue'])->toBeGreaterThanOrEqual(500)
            ->and($metrics['revenueChart'])->toHaveCount(7)
            ->and($metrics['expensesChart'])->toHaveCount(7)
            ->and($metrics['netIncomeChart'])->toHaveCount(7);
    });
});

describe('FinancialStatementService & ConsolidationService', function (): void {
    it('generates P&L, balance sheet, cash flow, and consolidated statements with intercompany eliminations', function (): void {
        $user = User::factory()->create();
        $teamA = Team::factory()->create(['user_id' => $user->id]);
        $teamB = Team::factory()->create(['user_id' => $user->id]);
        $user->forceFill(['current_team_id' => $teamA->id])->save();
        $this->actingAs($user);

        $cashA = Account::factory()->create(['team_id' => $teamA->id, 'account_type' => 'bank', 'account_name' => 'Cash A', 'opening_balance' => 1000]);
        $arA = Account::factory()->create(['team_id' => $teamA->id, 'account_type' => 'asset', 'account_name' => 'AR A', 'opening_balance' => 0]);
        $revA = Account::factory()->create(['team_id' => $teamA->id, 'account_type' => 'revenue', 'account_name' => 'Sales A']);
        $cogsA = Account::factory()->create(['team_id' => $teamA->id, 'account_type' => 'cost_of_goods_sold', 'account_name' => 'Cost of Goods A']);
        $expA = Account::factory()->create(['team_id' => $teamA->id, 'account_type' => 'expense', 'account_name' => 'Rent A']);
        $eqA = Account::factory()->create(['team_id' => $teamA->id, 'account_type' => 'equity', 'account_name' => 'Equity A', 'opening_balance' => 1000]);

        $apB = Account::factory()->create(['team_id' => $teamB->id, 'account_type' => 'liability', 'account_name' => 'AP B']);
        $expB = Account::factory()->create(['team_id' => $teamB->id, 'account_type' => 'expense', 'account_name' => 'Interco Exp B']);

        // Regular posted entry on Team A
        $je1 = JournalEntry::factory()->create([
            'team_id' => $teamA->id,
            'user_id' => $user->id,
            'entry_date' => '2026-01-15',
            'is_posted' => true,
        ]);
        $je1->lines()->create(['account_id' => $cashA->id, 'debit_amount' => 500, 'credit_amount' => 0]);
        $je1->lines()->create(['account_id' => $revA->id, 'debit_amount' => 0, 'credit_amount' => 500]);

        $je2 = JournalEntry::factory()->create([
            'team_id' => $teamA->id,
            'user_id' => $user->id,
            'entry_date' => '2026-01-16',
            'is_posted' => true,
        ]);
        $je2->lines()->create(['account_id' => $cogsA->id, 'debit_amount' => 100, 'credit_amount' => 0]);
        $je2->lines()->create(['account_id' => $expA->id, 'debit_amount' => 50, 'credit_amount' => 0]);
        $je2->lines()->create(['account_id' => $cashA->id, 'debit_amount' => 0, 'credit_amount' => 150]);

        // Intercompany posted entry on Team A (counterparty Team B)
        $jeInterA = JournalEntry::factory()->create([
            'team_id' => $teamA->id,
            'user_id' => $user->id,
            'counterparty_team_id' => $teamB->id,
            'entry_date' => '2026-01-20',
            'is_posted' => true,
        ]);
        $jeInterA->lines()->create(['account_id' => $arA->id, 'debit_amount' => 200, 'credit_amount' => 0]);
        $jeInterA->lines()->create(['account_id' => $revA->id, 'debit_amount' => 0, 'credit_amount' => 200]);

        // Intercompany posted entry on Team B (counterparty Team A)
        $jeInterB = JournalEntry::factory()->create([
            'team_id' => $teamB->id,
            'user_id' => $user->id,
            'counterparty_team_id' => $teamA->id,
            'entry_date' => '2026-01-20',
            'is_posted' => true,
        ]);
        $jeInterB->lines()->create(['account_id' => $expB->id, 'debit_amount' => 200, 'credit_amount' => 0]);
        $jeInterB->lines()->create(['account_id' => $apB->id, 'debit_amount' => 0, 'credit_amount' => 200]);

        $fs = app(FinancialStatementService::class);
        $start = Carbon::parse('2026-01-01');
        $end = Carbon::parse('2026-01-31');

        $pl = $fs->profitAndLoss($start, $end);
        expect($pl['revenue']['total'])->toBe(700.0)
            ->and($pl['cost_of_goods_sold']['total'])->toBe(100.0)
            ->and($pl['expenses']['total'])->toBe(50.0)
            ->and($pl['net_income'])->toBe(550.0);

        $bs = $fs->balanceSheet($end);
        expect($bs['assets']['total'])->toBeGreaterThan(0);

        $cf = $fs->cashFlowStatement($start, $end);
        expect($cf['operating_activities']['net_income'])->toBe(550.0)
            ->and($cf['beginning_cash'])->toBe(1000.0)
            ->and($cf['ending_cash'])->toBe(1350.0);

        // Consolidation with empty group first (covers empty memberIds early return)
        $emptyGroup = ConsolidationGroup::create(['name' => 'Empty', 'owner_team_id' => $teamA->id]);
        $consolidation = app(ConsolidationService::class);
        expect($consolidation->consolidatedProfitAndLoss($emptyGroup, $start, $end)['consolidated']['revenue'])->toBe(0.0);

        $group = ConsolidationGroup::create(['name' => 'Group AB', 'owner_team_id' => $teamA->id]);
        $group->members()->attach([$teamA->id, $teamB->id]);

        $cpl = $consolidation->consolidatedProfitAndLoss($group, $start, $end);
        expect($cpl['eliminations']['revenue'])->toBe(200.0)
            ->and($cpl['eliminations']['expenses'])->toBe(200.0)
            ->and($cpl['consolidated']['revenue'])->toBe(500.0);

        $cbs = $consolidation->consolidatedBalanceSheet($group, $end);
        expect($cbs['eliminations']['assets'])->toBe(200.0)
            ->and($cbs['eliminations']['liabilities'])->toBe(200.0);

        $ccf = $consolidation->consolidatedCashFlow($group, $start, $end);
        expect($ccf['consolidated']['ending_cash'])->toBe(1350.0);
    });
});

describe('ReconciliationService', function (): void {
    it('reconciles statements using rules (contains, equals, between, default) and exact/fuzzy matching', function (): void {
        $team = Team::factory()->create();
        $bankAccount = Account::factory()->create(['team_id' => $team->id]);
        $expenseAccount = Account::factory()->create(['team_id' => $team->id]);

        $statement = BankStatement::factory()->create([
            'team_id' => $team->id,
            'account_id' => $bankAccount->id,
            'statement_date' => '2026-01-15',
            'total_credits' => 300,
            'total_debits' => 150,
            'ending_balance' => 150,
            'reconciled' => false,
        ]);

        // Rules covering contains, equals (amount & reference), between, and unknown operator
        ReconciliationRule::factory()->create([
            'team_id' => $team->id,
            'is_active' => true,
            'priority' => 1,
            'match_field' => 'description',
            'match_operator' => 'contains',
            'match_value' => 'Stripe',
            'action_account_id' => $expenseAccount->id,
        ]);
        ReconciliationRule::factory()->create([
            'team_id' => $team->id,
            'is_active' => true,
            'priority' => 2,
            'match_field' => 'amount',
            'match_operator' => 'equals',
            'match_value' => '-50',
            'action_account_id' => $expenseAccount->id,
        ]);
        ReconciliationRule::factory()->create([
            'team_id' => $team->id,
            'is_active' => true,
            'priority' => 3,
            'match_field' => 'reference',
            'match_operator' => 'equals',
            'match_value' => 'REF-MATCH',
            'action_account_id' => null,
        ]);
        ReconciliationRule::factory()->create([
            'team_id' => $team->id,
            'is_active' => true,
            'priority' => 4,
            'match_field' => 'amount',
            'match_operator' => 'between',
            'match_value' => '90',
            'match_value_secondary' => '110',
            'action_account_id' => $expenseAccount->id,
        ]);
        ReconciliationRule::factory()->create([
            'team_id' => $team->id,
            'is_active' => true,
            'priority' => 5,
            'match_field' => 'description',
            'match_operator' => 'unknown_op',
            'match_value' => 'x',
        ]);

        // Matched by contains rule
        Transaction::factory()->create([
            'team_id' => $team->id,
            'account_id' => $bankAccount->id,
            'transaction_date' => '2026-01-10',
            'amount' => 100,
            'description' => 'Stripe Payout',
            'reconciled' => false,
        ]);
        // Matched by amount equals rule
        Transaction::factory()->create([
            'team_id' => $team->id,
            'account_id' => $bankAccount->id,
            'transaction_date' => '2026-01-11',
            'amount' => -50,
            'description' => 'Hosting Fee',
            'reconciled' => false,
        ]);
        // Matched by reference equals rule
        Transaction::factory()->create([
            'team_id' => $team->id,
            'account_id' => $bankAccount->id,
            'transaction_date' => '2026-01-12',
            'amount' => 100,
            'external_id' => 'REF-MATCH',
            'description' => 'Ref Payment',
            'reconciled' => false,
        ]);
        // Matched by exact bank_statement_id link
        Transaction::factory()->create([
            'team_id' => $team->id,
            'account_id' => $bankAccount->id,
            'bank_statement_id' => $statement->id,
            'transaction_date' => '2026-01-13',
            'amount' => -100,
            'description' => 'Exact Link',
            'reconciled' => false,
        ]);
        // Unmatched transaction triggering balance_mismatch & unmatched_transaction
        Transaction::factory()->create([
            'team_id' => $team->id,
            'account_id' => $bankAccount->id,
            'transaction_date' => '2026-01-14',
            'amount' => -25,
            'description' => 'Unmatched Fee',
            'reconciled' => false,
        ]);

        $service = new ReconciliationService();
        $result = $service->reconcileStatement($statement);

        expect($result['matched_transactions'])->toBe(4)
            ->and($result['unmatched_transactions'])->toBe(1)
            ->and($result['reconciled'])->toBeFalse()
            ->and($result['discrepancies']->count())->toBeGreaterThanOrEqual(2);
    });
});

describe('AccountCsvService', function (): void {
    it('exports and imports accounts covering all validation errors, blanks, defaults, and parent links', function (): void {
        $parent = Account::factory()->create(['account_number' => 1000, 'account_name' => 'Assets', 'account_type' => 'asset']);
        $child = Account::factory()->create(['account_number' => 1010, 'account_name' => 'Cash', 'account_type' => 'asset', 'parent_id' => $parent->id]);

        $service = new AccountCsvService();
        $csvOut = $service->export(collect([$parent->load('parent'), $child->load('parent')]));
        expect($csvOut)->toContain('account_number,account_name');

        // Default export (null argument)
        expect($service->export())->toContain('1000');

        $importCsv = implode("\n", [
            'account_number,account_name,account_type,normal_balance,opening_balance,parent_number,description,is_active',
            '', // blank line
            ',,,,,,,', // all-empty cells line
            '0,Missing Num,asset,debit,0,,desc,1', // missing account_number
            '2001,Bad Type,bogus,debit,0,,desc,1', // invalid account_type
            '2002,Bad Normal,asset,sideways,0,,desc,1', // invalid normal_balance
            '2003,Auto Debit,expense,,100,,Auto normal,1', // empty normal_balance -> debit
            '2004,Auto Credit,revenue,,200,2003,Has parent,0', // empty normal_balance -> credit + valid parent
            '2005,Orphan,asset,debit,,999999,Missing parent,', // missing parent error
        ]);

        $res = $service->import($importCsv);
        expect($res['created'])->toBe(3)
            ->and($res['errors'])->toHaveCount(4);
    });
});

describe('AgingReportService', function (): void {
    it('buckets pending invoices across all five aging brackets', function (): void {
        $customer = Customer::factory()->create();

        // current (due in future -> diffInDays negative or <=0 with Carbon? Note: diffInDays is signed in Carbon 3)
        Invoice::factory()->create(['customer_id' => $customer->id, 'payment_status' => 'pending', 'total_amount' => 100, 'due_date' => now()->addDays(5)]);
        Invoice::factory()->create(['customer_id' => $customer->id, 'payment_status' => 'pending', 'total_amount' => 200, 'due_date' => now()->subDays(15)]);
        Invoice::factory()->create(['customer_id' => $customer->id, 'payment_status' => 'pending', 'total_amount' => 300, 'due_date' => now()->subDays(45)]);
        Invoice::factory()->create(['customer_id' => $customer->id, 'payment_status' => 'pending', 'total_amount' => 400, 'due_date' => now()->subDays(75)]);
        Invoice::factory()->create(['customer_id' => $customer->id, 'payment_status' => 'pending', 'total_amount' => 500, 'due_date' => now()->subDays(120)]);

        $report = (new AgingReportService())->generateAgingReport();
        expect($report)->toHaveCount(1);
        $entry = $report->first();
        expect($entry['customer_name'])->toBe($customer->customer_name)
            ->and($entry['total_due'])->toBeGreaterThan(0);
    });
});

describe('BudgetService & ForecastService', function (): void {
    it('compares budgets, generates moving average forecast, and computes rolling baseline and scenario forecasts', function (): void {
        $user = User::factory()->create();
        $team = Team::factory()->create(['user_id' => $user->id]);
        $user->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($user);

        $revAccount = Account::factory()->create(['team_id' => $team->id, 'account_type' => 'revenue']);
        $expAccount = Account::factory()->create(['team_id' => $team->id, 'account_type' => 'expense']);

        Transaction::factory()->create(['team_id' => $team->id, 'account_id' => $revAccount->id, 'transaction_date' => '2026-01-10', 'amount' => 1000]);
        Transaction::factory()->create(['team_id' => $team->id, 'account_id' => $revAccount->id, 'transaction_date' => '2026-01-20', 'amount' => 2000]);
        Transaction::factory()->create(['team_id' => $team->id, 'account_id' => $expAccount->id, 'transaction_date' => '2026-01-15', 'amount' => 400]);

        $budget = Budget::factory()->create([
            'team_id' => $team->id,
            'account_id' => $revAccount->id,
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-31',
            'planned_amount' => 5000,
        ]);
        $zeroPlanBudget = Budget::factory()->create([
            'team_id' => $team->id,
            'account_id' => $expAccount->id,
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-31',
            'planned_amount' => 0,
        ]);

        $budgetService = new BudgetService();
        $comparison = $budgetService->getBudgetComparison('2026-01-01', '2026-01-31');
        expect($comparison)->toHaveCount(2);

        // Cover null-account guard in BudgetService::calculateActualAmount
        $calcActual = new ReflectionMethod(BudgetService::class, 'calculateActualAmount');
        expect($calcActual->invoke($budgetService, null, $zeroPlanBudget))->toBe(0);

        $forecast = $budgetService->generateForecast($budget);
        expect((float) $forecast)->toBe(1500.0)
            ->and($budget->fresh()->forecast_method)->toBe('moving_average');

        // ForecastService
        $scenario = ForecastScenario::create(['name' => 'Growth +10%', 'team_id' => $team->id]);
        $scenario->lines()->create(['account_type' => 'revenue', 'adjustment_pct' => 10]);
        $scenario->lines()->create(['account_type' => 'expense', 'adjustment_pct' => -5]);

        $forecastService = new ForecastService();
        $cmp = $forecastService->compare(3, $scenario->load('lines'));
        expect($cmp['baseline']['revenue'][1])->toBe(1500.0)
            ->and($cmp['scenario']['revenue'][1])->toBe(1650.0)
            ->and($cmp['scenario_net_income'])->toHaveCount(3);
    });
});

describe('ExchangeRateService & FxRevaluationService', function (): void {
    it('fetches, updates, and reads latest exchange rates and posts FX gain/loss settlements', function (): void {
        config()->set('services.exchange_rate_api', [
            'key' => 'fx-key',
            'url' => 'https://fx.test/latest',
        ]);

        $usd = Currency::factory()->create(['code' => 'USD', 'is_default' => true]);
        $gbp = Currency::factory()->create(['code' => 'GBP', 'is_default' => false]);

        Http::fake([
            'fx.test/latest*' => Http::response(['data' => ['GBP' => 0.79]]),
        ]);

        $service = new ExchangeRateService();
        // No rate yet -> triggers updateExchangeRates()
        $rate = $service->getExchangeRate($usd, $gbp);
        expect((float) $rate)->toBe(0.79);

        $latest = $service->getLatestRates();
        expect($latest)->toHaveCount(1)
            ->and($latest[0]['from'])->toBe('USD')
            ->and($latest[0]['to'])->toBe('GBP');

        // FxRevaluationService
        $user = User::factory()->create();
        $team = Team::factory()->create(['user_id' => $user->id]);
        $user->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($user);

        $counter = Account::factory()->create(['team_id' => $team->id]);
        $fxGain = Account::factory()->create(['team_id' => $team->id, 'account_type' => 'revenue']);
        $fxLoss = Account::factory()->create(['team_id' => $team->id, 'account_type' => 'expense']);

        $fx = new FxRevaluationService();
        expect($fx->postSettlement(100, 1.20, 1.20, $counter, $fxGain, $fxLoss))->toBeNull();

        $gainEntry = $fx->postSettlement(100, 1.20, 1.25, $counter, $fxGain, $fxLoss);
        expect($gainEntry)->toBeInstanceOf(JournalEntry::class)
            ->and($gainEntry->lines()->count())->toBe(2);

        $lossEntry = $fx->postSettlement(100, 1.25, 1.20, $counter, $fxGain, $fxLoss);
        expect($lossEntry)->toBeInstanceOf(JournalEntry::class)
            ->and($lossEntry->lines()->count())->toBe(2);
    });
});

describe('ProjectReportService', function (): void {
    it('calculates project financials, direct/indirect costs, and budget variance', function (): void {
        $user = User::factory()->create();
        $team = Team::factory()->create(['user_id' => $user->id]);
        $project = Project::factory()->create(['team_id' => $team->id]);
        $account = Account::factory()->create(['team_id' => $team->id]);

        Transaction::factory()->create([
            'team_id' => $team->id,
            'account_id' => $account->id,
            'project_id' => $project->id,
            'transaction_date' => '2026-01-10',
            'type' => 'credit',
            'amount' => 1000,
            'description' => 'Milestone 1',
        ]);

        Expense::create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'project_id' => $project->id,
            'date' => '2026-01-12',
            'amount' => 300,
            'description' => 'Materials',
            'is_indirect' => false,
        ]);
        Expense::create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'project_id' => $project->id,
            'date' => '2026-01-14',
            'amount' => 400,
            'description' => 'Overhead',
            'is_indirect' => true,
            'allocation_percentage' => 50,
        ]);

        Budget::factory()->create([
            'team_id' => $team->id,
            'account_id' => $account->id,
            'project_id' => $project->id,
            'start_date' => '2026-01-05',
            'end_date' => '2026-01-31',
            'planned_amount' => 1000,
        ]);

        $service = new ProjectReportService();
        $financials = $service->getProjectFinancials($project, '2026-01-01', '2026-01-31');
        expect((float) $financials['summary']['revenue'])->toBe(1000.0)
            ->and((float) $financials['summary']['direct_costs'])->toBe(300.0)
            ->and((float) $financials['summary']['indirect_costs'])->toBe(200.0)
            ->and((float) $financials['summary']['gross_profit'])->toBe(500.0);

        $variance = $service->getBudgetVariance($project, '2026-01-01', '2026-01-31');
        expect((float) $variance['budget_amount'])->toBe(1000.0)
            ->and((float) $variance['variance'])->toBe(500.0);
    });
});

describe('RevenueRecognitionService', function (): void {
    it('creates schedules with full validation and recognizes due entries to completion', function (): void {
        $user = User::factory()->create();
        $team = Team::factory()->create(['user_id' => $user->id]);
        $customer = Customer::factory()->create(['team_id' => $team->id]);
        $deferred = Account::factory()->create(['team_id' => $team->id, 'account_type' => 'liability']);
        $revenue = Account::factory()->create(['team_id' => $team->id, 'account_type' => 'revenue']);

        $invoice = Invoice::factory()->create([
            'team_id' => $team->id,
            'customer_id' => $customer->id,
            'invoice_date' => now()->subMonths(2)->toDateString(),
            'total_amount' => 100.00,
            'journal_entry_id' => null,
        ]);

        $service = new RevenueRecognitionService();

        // Guard rails
        expect(fn () => $service->createFromInvoice($invoice, 0, $deferred, $revenue))->toThrow(InvalidArgumentException::class);
        expect(fn () => $service->createFromInvoice($invoice, 3, $deferred, $deferred))->toThrow(InvalidArgumentException::class);

        $postedInvoice = Invoice::factory()->create([
            'team_id' => $team->id,
            'customer_id' => $customer->id,
            'journal_entry_id' => JournalEntry::factory()->create(['team_id' => $team->id, 'user_id' => $user->id])->id,
        ]);
        expect(fn () => $service->createFromInvoice($postedInvoice, 3, $deferred, $revenue))->toThrow(InvalidArgumentException::class);

        // Create 3-period schedule
        $schedule = $service->createFromInvoice($invoice, 3, $deferred, $revenue);
        expect($schedule->entries()->count())->toBe(3);

        // Duplicate schedule guard
        expect(fn () => $service->createFromInvoice($invoice, 3, $deferred, $revenue))->toThrow(InvalidArgumentException::class);

        // Invoice not yet posted -> recognizes 0
        expect($service->recognizeDue($schedule))->toBe(0);

        // Post invoice -> recognizes all 3 due periods and marks completed
        $je = JournalEntry::factory()->create(['team_id' => $team->id, 'user_id' => $user->id]);
        $invoice->forceFill(['journal_entry_id' => $je->id])->save();

        expect($service->recognizeDue($schedule->fresh()))->toBe(3)
            ->and($schedule->fresh()->status)->toBe('completed');

        // Completed schedule -> early return 0
        expect($service->recognizeDue($schedule->fresh()))->toBe(0);
    });
});
