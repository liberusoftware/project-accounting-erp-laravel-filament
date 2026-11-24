<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Liberu\Accounting\FinancialStatements\Enums\StatementType;
use Liberu\Accounting\FinancialStatements\Exceptions\InvalidStatementRequest;
use Liberu\Accounting\FinancialStatements\FinancialStatementsServiceProvider;
use Liberu\Accounting\FinancialStatements\Queries\StatementQuery;

it('registers the financial statements service provider and singleton query', function (): void {
    $provider = app()->getProvider(FinancialStatementsServiceProvider::class)
        ?? new FinancialStatementsServiceProvider(app());

    $provider->register();

    $q1 = app(StatementQuery::class);
    $q2 = app(StatementQuery::class);

    expect($provider)->toBeInstanceOf(FinancialStatementsServiceProvider::class)
        ->and($q1)->toBeInstanceOf(StatementQuery::class)
        ->and($q1)->toBe($q2);
});

it('exposes statement type enum and invalid statement request exception', function (): void {
    expect(array_map(static fn (StatementType $t): string => $t->value, StatementType::cases()))
        ->toBe(['profit_and_loss', 'balance_sheet', 'cash_flow', 'changes_in_equity']);

    $exception = new InvalidStatementRequest('Bad request.');
    expect($exception)->toBeInstanceOf(InvalidArgumentException::class)
        ->and($exception->getMessage())->toBe('Bad request.');
});

it('rejects invalid book, date, period, and dimension inputs on StatementQuery', function (): void {
    $query = app(StatementQuery::class);

    $entityId = DB::table('accounting_legal_entities')->insertGetId([
        'name' => 'Statements Validation Ltd',
        'currency_code' => 'GBP',
        'accounting_basis' => 'accrual',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $bookId = DB::table('accounting_books')->insertGetId([
        'legal_entity_id' => $entityId,
        'name' => 'Main Book',
        'code' => 'MAIN-VAL',
        'accounting_basis' => 'accrual',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => $query->profitAndLoss(0, '2026-01-01', '2026-01-31'))
        ->toThrow(InvalidStatementRequest::class, 'The requested accounting book does not exist.');
    expect(fn () => $query->profitAndLoss(999999, '2026-01-01', '2026-01-31'))
        ->toThrow(InvalidStatementRequest::class, 'The requested accounting book does not exist.');
    expect(fn () => $query->balanceSheet(999999, '2026-01-31'))
        ->toThrow(InvalidStatementRequest::class, 'The requested accounting book does not exist.');

    expect(fn () => $query->profitAndLoss($bookId, '2026/01/01', '2026-01-31'))
        ->toThrow(InvalidStatementRequest::class, 'Statement dates must use the YYYY-MM-DD format.');
    expect(fn () => $query->profitAndLoss($bookId, '2026-02-30', '2026-03-01'))
        ->toThrow(InvalidStatementRequest::class, 'Statement dates must use the YYYY-MM-DD format.');
    expect(fn () => $query->balanceSheet($bookId, 'invalid-date'))
        ->toThrow(InvalidStatementRequest::class, 'Statement dates must use the YYYY-MM-DD format.');

    expect(fn () => $query->profitAndLoss($bookId, '2026-05-01', '2026-04-01'))
        ->toThrow(InvalidStatementRequest::class, 'The statement start date must not be after its end date.');

    expect(fn () => $query->profitAndLoss($bookId, '2026-01-01', '2026-01-31', ['1bad' => 'ops']))
        ->toThrow(InvalidStatementRequest::class, 'Statement dimensions must be named scalar filters.');
    expect(fn () => $query->profitAndLoss($bookId, '2026-01-01', '2026-01-31', ['dept' => ['nested']]))
        ->toThrow(InvalidStatementRequest::class, 'Statement dimensions must be named scalar filters.');
    expect(fn () => $query->profitAndLoss($bookId, '2026-01-01', '2026-01-31', [0 => 'ops']))
        ->toThrow(InvalidStatementRequest::class, 'Statement dimensions must be named scalar filters.');
});

it('builds profit and loss, balance sheet, cash flow, changes in equity, comparative, and drill-through reports', function (): void {
    $query = app(StatementQuery::class);

    $entityId = DB::table('accounting_legal_entities')->insertGetId([
        'name' => 'Statements Corp',
        'currency_code' => 'GBP',
        'accounting_basis' => 'accrual',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $bookId = DB::table('accounting_books')->insertGetId([
        'legal_entity_id' => $entityId,
        'name' => 'Statutory Book',
        'code' => 'STAT-1',
        'accounting_basis' => 'accrual',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Empty book reports
    $emptyPnl = $query->profitAndLoss($bookId, '2026-01-01', '2026-12-31');
    expect($emptyPnl['type'])->toBe('profit_and_loss')
        ->and($emptyPnl['revenue']['total'])->toBe(0.0)
        ->and($emptyPnl['net_income'])->toBe(0.0);

    $createAccount = static function (string $code, string $name, string $type, string $normal) use ($entityId): int {
        return DB::table('accounting_chart_accounts')->insertGetId([
            'legal_entity_id' => $entityId,
            'code' => $code,
            'name' => $name,
            'type' => $type,
            'normal_balance' => $normal,
            'is_control_account' => false,
            'allow_manual_entry' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    };

    $bankAcc = $createAccount('1000', 'Operating Bank', 'bank', 'debit');
    $liabilityAcc = $createAccount('2000', 'Accounts Payable', 'current_liability', 'credit');
    $equityAcc = $createAccount('3000', 'Share Capital', 'equity', 'credit');
    $revenueAcc = $createAccount('4000', 'Product Sales', 'revenue', 'credit');
    $cogsAcc = $createAccount('5000', 'Cost of Sales', 'cost_of_goods_sold', 'debit');
    $expenseAcc = $createAccount('6000', 'Rent Expense', 'expense', 'debit');

    $postEntry = static function (string $number, string $date, string $description, array $lines, string $status = 'posted') use ($bookId): int {
        $entryId = DB::table('accounting_journal_entries')->insertGetId([
            'book_id' => $bookId,
            'entry_number' => $number,
            'entry_date' => $date,
            'journal_type' => 'general',
            'status' => $status,
            'description' => $description,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ($lines as $line) {
            DB::table('accounting_journal_lines')->insert([
                'journal_entry_id' => $entryId,
                'account_id' => $line['account_id'],
                'debit' => $line['debit'],
                'credit' => $line['credit'],
                'description' => $line['description'] ?? null,
                'dimensions' => isset($line['dimensions']) ? json_encode($line['dimensions']) : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $entryId;
    };

    // Prior period (2025-12-15): Owner invests 1000 into bank
    $postEntry('JE-001', '2025-12-15', 'Initial capital', [
        ['account_id' => $bankAcc, 'debit' => 1000, 'credit' => 0, 'dimensions' => ['dept' => 'hq']],
        ['account_id' => $equityAcc, 'debit' => 0, 'credit' => 1000, 'dimensions' => ['dept' => 'hq']],
    ]);

    // Current period (2026-01-10): Revenue 500 -> Bank
    $postEntry('JE-002', '2026-01-10', 'January sales', [
        ['account_id' => $bankAcc, 'debit' => 500, 'credit' => 0, 'description' => 'Cash in', 'dimensions' => ['dept' => 'sales']],
        ['account_id' => $revenueAcc, 'debit' => 0, 'credit' => 500, 'description' => 'Sales rev', 'dimensions' => ['dept' => 'sales']],
    ]);

    // Current period (2026-01-15): COGS 150 -> Payable
    $postEntry('JE-003', '2026-01-15', 'Supplier COGS', [
        ['account_id' => $cogsAcc, 'debit' => 150, 'credit' => 0, 'dimensions' => ['dept' => 'sales']],
        ['account_id' => $liabilityAcc, 'debit' => 0, 'credit' => 150, 'dimensions' => ['dept' => 'sales']],
    ]);

    // Current period (2026-01-20): Rent expense 100 -> Bank
    $postEntry('JE-004', '2026-01-20', 'Office rent', [
        ['account_id' => $expenseAcc, 'debit' => 100, 'credit' => 0, 'dimensions' => ['dept' => 'hq']],
        ['account_id' => $bankAcc, 'debit' => 0, 'credit' => 100, 'dimensions' => ['dept' => 'hq']],
    ]);

    // Draft entry must be excluded from all statements
    $postEntry('JE-DRAFT', '2026-01-25', 'Draft ignored', [
        ['account_id' => $bankAcc, 'debit' => 9999, 'credit' => 0],
        ['account_id' => $revenueAcc, 'debit' => 0, 'credit' => 9999],
    ], 'draft');

    // 1. Profit & Loss
    $pnl = $query->profitAndLoss($bookId, '2026-01-01', '2026-01-31');
    expect($pnl['type'])->toBe('profit_and_loss')
        ->and($pnl['period'])->toBe(['start_date' => '2026-01-01', 'end_date' => '2026-01-31'])
        ->and($pnl['revenue']['total'])->toBe(500.0)
        ->and($pnl['cost_of_goods_sold']['total'])->toBe(150.0)
        ->and($pnl['gross_profit'])->toBe(350.0)
        ->and($pnl['expenses']['total'])->toBe(100.0)
        ->and($pnl['net_income'])->toBe(250.0);

    // P&L with dimension filter
    $salesPnl = $query->profitAndLoss($bookId, '2026-01-01', '2026-01-31', ['dept' => 'sales']);
    expect($salesPnl['dimensions'])->toBe(['dept' => 'sales'])
        ->and($salesPnl['revenue']['total'])->toBe(500.0)
        ->and($salesPnl['cost_of_goods_sold']['total'])->toBe(150.0)
        ->and($salesPnl['expenses']['total'])->toBe(0.0)
        ->and($salesPnl['net_income'])->toBe(350.0);

    // 2. Balance Sheet as of 2026-01-31
    // Bank = 1000 + 500 - 100 = 1400; Liabilities = 150; Equity = 1000 + 250 retained earnings = 1250
    $bs = $query->balanceSheet($bookId, '2026-01-31');
    expect($bs['type'])->toBe('balance_sheet')
        ->and($bs['as_of_date'])->toBe('2026-01-31')
        ->and($bs['assets']['total'])->toBe(1400.0)
        ->and($bs['liabilities']['total'])->toBe(150.0)
        ->and($bs['equity']['retained_earnings'])->toBe(250.0)
        ->and($bs['equity']['total'])->toBe(1250.0)
        ->and($bs['total_liabilities_and_equity'])->toBe(1400.0)
        ->and($bs['balance_check'])->toBe(0.0);

    // 3. Cash Flow
    // Beginning cash (as of 2025-12-31) = 1000; Period bank net = 500 - 100 = 400
    $cf = $query->cashFlow($bookId, '2026-01-01', '2026-01-31');
    expect($cf['type'])->toBe('cash_flow')
        ->and($cf['beginning_cash'])->toBe(1000.0)
        ->and($cf['ending_cash'])->toBe(400.0)
        ->and($cf['net_change_in_cash'])->toBe(-600.0)
        ->and($cf['operating_activities']['net_cash_from_operations'])->toBe(-600.0)
        ->and($cf['investing_activities']['net_cash_from_investing'])->toBe(0.0)
        ->and($cf['financing_activities']['net_cash_from_financing'])->toBe(0.0);

    // 4. Changes in Equity
    $cie = $query->changesInEquity($bookId, '2026-01-01', '2026-01-31');
    expect($cie['type'])->toBe('changes_in_equity')
        ->and($cie['accounts'])->toBe([])
        ->and($cie['net_income'])->toBe(250.0)
        ->and($cie['total_change'])->toBe(250.0);

    // 5. Comparative P&L
    $comp = $query->comparative($bookId, '2026-01-01', '2026-01-31', '2025-12-01', '2025-12-31');
    expect($comp['type'])->toBe('comparative')
        ->and($comp['current']['net_income'])->toBe(250.0)
        ->and($comp['comparative']['net_income'])->toBe(0.0);

    // 6. Drill-through
    $drill = $query->drillThrough($bookId, $bankAcc, '2026-01-01', '2026-01-31');
    expect($drill)->toHaveCount(2)
        ->and($drill[0]['entry_number'])->toBe('JE-002')
        ->and((float) $drill[0]['debit'])->toBe(500.0)
        ->and($drill[1]['entry_number'])->toBe('JE-004')
        ->and((float) $drill[1]['credit'])->toBe(100.0);
});

it('falls back to generic asset accounts in cash flow when no bank or cash accounts have activity', function (): void {
    $query = app(StatementQuery::class);

    $entityId = DB::table('accounting_legal_entities')->insertGetId([
        'name' => 'Legacy Cash Flow Corp',
        'currency_code' => 'USD',
        'accounting_basis' => 'accrual',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $bookId = DB::table('accounting_books')->insertGetId([
        'legal_entity_id' => $entityId,
        'name' => 'Legacy Book',
        'code' => 'LEG-1',
        'accounting_basis' => 'accrual',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $assetAcc = DB::table('accounting_chart_accounts')->insertGetId([
        'legal_entity_id' => $entityId,
        'code' => '1010',
        'name' => 'General Cash Asset',
        'type' => 'asset',
        'normal_balance' => 'debit',
        'is_control_account' => false,
        'allow_manual_entry' => true,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $revAcc = DB::table('accounting_chart_accounts')->insertGetId([
        'legal_entity_id' => $entityId,
        'code' => '4010',
        'name' => 'Legacy Revenue',
        'type' => 'revenue',
        'normal_balance' => 'credit',
        'is_control_account' => false,
        'allow_manual_entry' => true,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $entryId = DB::table('accounting_journal_entries')->insertGetId([
        'book_id' => $bookId,
        'entry_number' => 'JE-LEG-1',
        'entry_date' => '2026-02-10',
        'journal_type' => 'general',
        'status' => 'posted',
        'description' => 'Legacy cash sale',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('accounting_journal_lines')->insert([
        ['journal_entry_id' => $entryId, 'account_id' => $assetAcc, 'debit' => 300, 'credit' => 0, 'created_at' => now(), 'updated_at' => now()],
        ['journal_entry_id' => $entryId, 'account_id' => $revAcc, 'debit' => 0, 'credit' => 300, 'created_at' => now(), 'updated_at' => now()],
    ]);

    $cf = $query->cashFlow($bookId, '2026-02-01', '2026-02-28');
    expect($cf['operating_activities']['accounts'])->toHaveCount(1)
        ->and($cf['ending_cash'])->toBe(300.0)
        ->and($cf['beginning_cash'])->toBe(0.0)
        ->and($cf['net_change_in_cash'])->toBe(300.0);
});
