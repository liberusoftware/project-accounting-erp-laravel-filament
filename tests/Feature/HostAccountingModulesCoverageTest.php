<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Liberu\Accounting\AccountsPayable\Models\PayableOpenItem;
use Liberu\Accounting\AccountsReceivable\Actions\SetCreditControl;
use Liberu\Accounting\AccountsReceivable\Exceptions\InvalidReceivable;
use Liberu\Accounting\AccountsReceivable\Models\ReceivableAccount;
use Liberu\Accounting\AccountsReceivable\Models\ReceivableDispute;
use Liberu\Accounting\AccountsReceivable\Models\ReceivableOpenItem;
use Liberu\Accounting\AccountsReceivable\Models\ReceivableReceipt;
use Liberu\Accounting\AccountsReceivableApi\AccountsReceivableApiServiceProvider;
use Liberu\Accounting\AccountsReceivableApi\Http\Controllers\AccountsReceivableController;
use Liberu\Accounting\AccountsReceivableApi\Http\Resources\ReceivableDisputeResource;
use Liberu\Accounting\AccountsReceivableApi\Http\Resources\ReceivableOpenItemResource;
use Liberu\Accounting\AccountsReceivableApi\Http\Resources\ReceivableReceiptResource;
use Liberu\Accounting\AccountsReceivableApi\Policies\AccountsReceivablePolicy;
use Liberu\Accounting\ChartOfAccounts\Enums\AccountType;
use Liberu\Accounting\ChartOfAccounts\Enums\NormalBalance;
use Liberu\Accounting\ChartOfAccounts\Exceptions\InvalidAccount;
use Liberu\Accounting\ChartOfAccounts\Models\Account;
use Liberu\Accounting\Core\Models\LegalEntity;
use Liberu\Accounting\Erp\Models\ApprovalRule;
use Liberu\Accounting\Erp\Models\BankAccountBalance;
use Liberu\Accounting\Erp\Models\BankConnection;
use Liberu\Accounting\Erp\Models\BankFeedTransaction;
use Liberu\Accounting\Erp\Models\Customer;
use Liberu\Accounting\Erp\Models\OutboundPayment;
use Liberu\Accounting\Erp\Models\Team;
use Liberu\Accounting\Erp\Models\Transaction;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\Erp\Models\Vendor;
use Liberu\Accounting\Erp\Services\RevolutService;
use Liberu\Accounting\EstimatesAndQuotes\Actions\AddEstimateItem;
use Liberu\Accounting\EstimatesAndQuotes\Actions\ConvertEstimate;
use Liberu\Accounting\EstimatesAndQuotes\Actions\DecideEstimate;
use Liberu\Accounting\EstimatesAndQuotes\Actions\ExpireEstimate;
use Liberu\Accounting\EstimatesAndQuotes\Actions\SendEstimate;
use Liberu\Accounting\EstimatesAndQuotes\Models\Estimate as QuoteEstimate;
use Liberu\Accounting\EstimatesAndQuotesApi\EstimatesAndQuotesApiServiceProvider;
use Liberu\Accounting\EstimatesAndQuotesApi\Http\Controllers\EstimatesController;
use Liberu\Accounting\FinancialMasterData\Actions\SaveReferenceData;
use Liberu\Accounting\FinancialMasterData\Enums\PartyType;
use Liberu\Accounting\FinancialMasterData\Exceptions\DuplicateMasterRecord;
use Liberu\Accounting\FinancialMasterData\Models\Address;
use Liberu\Accounting\FinancialMasterData\Models\ItemService;
use Liberu\Accounting\FinancialMasterData\Models\Party;
use Liberu\Accounting\FinancialMasterDataApi\FinancialMasterDataApiServiceProvider;
use Liberu\Accounting\FinancialMasterDataApi\Http\Controllers\PartyDetailController;
use Liberu\Accounting\FinancialMasterDataApi\Http\Controllers\ReferenceDataController;
use Liberu\Accounting\FinancialMasterDataApi\Http\Requests\StorePartyDetailRequest;
use Liberu\Accounting\FinancialMasterDataApi\Http\Requests\StorePartyRequest;
use Liberu\Accounting\FinancialMasterDataApi\Http\Requests\StoreReferenceDataRequest;
use Liberu\Accounting\FinancialMasterDataApi\Http\Requests\UpdatePartyRequest;
use Liberu\Accounting\FinancialMasterDataApi\Http\Requests\UpdateReferenceDataRequest;
use Liberu\Accounting\FinancialMasterDataApi\Policies\PartyPolicy;
use Liberu\Accounting\GeneralLedger\Exceptions\InvalidJournal;
use Liberu\Accounting\GeneralLedger\Models\RecurringJournal;
use Liberu\Accounting\OpeningBalances\Actions\CreateOpeningBalanceBatch;
use Liberu\Accounting\OpeningBalances\Actions\ValidateOpeningBalances;
use Liberu\Accounting\OpeningBalances\Enums\EntryStatus;
use Liberu\Accounting\OpeningBalances\Enums\OpeningBalanceStatus;
use Liberu\Accounting\OpeningBalances\Exceptions\InvalidOpeningBalance;
use Liberu\Accounting\OpeningBalances\Models\OpeningBalanceBatch;
use Liberu\Accounting\SalesInvoicing\Actions\CreateInvoice;
use Liberu\Accounting\SalesInvoicing\Actions\GenerateInvoicePdf;
use Liberu\Accounting\SalesInvoicing\Actions\SetInvoiceBranding;
use Liberu\Accounting\SalesInvoicing\Actions\SetRecurringSource;
use Liberu\Accounting\SalesInvoicing\Enums\InvoiceStatus;
use Liberu\Accounting\SalesInvoicing\Exceptions\InvalidInvoice;
use Liberu\Accounting\SalesInvoicing\Models\SalesInvoice;
use Liberu\Accounting\SalesInvoicing\Models\SalesInvoiceDeposit;
use Liberu\Accounting\SalesInvoicing\Models\SalesInvoiceLine;
use Liberu\Accounting\SalesInvoicingApi\Policies\SalesInvoicingPolicy;
use Liberu\Accounting\SalesInvoicingApi\SalesInvoicingApiServiceProvider;
use Liberu\Accounting\SpreadsheetMigration\Actions\CreateMigrationTemplate;
use Liberu\Accounting\SpreadsheetMigration\Actions\ValidateMigration;
use Liberu\Accounting\SpreadsheetMigration\Exceptions\InvalidMigration;
use Liberu\Accounting\SpreadsheetMigration\Models\MigrationRun;
use Liberu\Accounting\SupplierBillsApi\Policies\SupplierBillsPolicy;
use Liberu\Accounting\SupplierBillsApi\SupplierBillsApiServiceProvider;
use Liberu\Accounting\TaxCore\Actions\CalculateTax;
use Liberu\Accounting\TaxCore\Actions\CreateTaxRule;
use Liberu\Accounting\TaxCore\Enums\TaxTreatment;
use Liberu\Accounting\TaxCore\Exceptions\InvalidTaxRule;
use Liberu\Accounting\TaxCore\Models\TaxEvidence;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

function makeHostAccountingRevolutUserAndTeam(): array
{
    $user = User::factory()->create();
    $team = Team::forceCreate([
        'user_id' => $user->id,
        'name' => 'Host Revolut Team '.$user->id,
        'personal_team' => true,
    ]);
    $user->forceFill(['current_team_id' => $team->id])->save();

    return [$user, $team];
}

it('covers PayableOpenItem, ReceivableOpenItem, SalesInvoiceDeposit, and MigrationRun models', function (): void {
    $payable = new PayableOpenItem([
        'original_amount' => 250.00,
        'paid_amount' => 100.00,
    ]);

    expect($payable->party())->toBeInstanceOf(BelongsTo::class)
        ->and($payable->applications())->toBeInstanceOf(HasMany::class)
        ->and($payable->disputes())->toBeInstanceOf(HasMany::class)
        ->and($payable->outstanding())->toBe(150.0);

    $receivable = new ReceivableOpenItem([
        'original_amount' => 400.00,
        'applied_amount' => 125.00,
    ]);

    expect($receivable->party())->toBeInstanceOf(BelongsTo::class)
        ->and($receivable->applications())->toBeInstanceOf(HasMany::class)
        ->and($receivable->disputes())->toBeInstanceOf(HasMany::class)
        ->and($receivable->outstanding())->toBe(275.0);

    $deposit = new SalesInvoiceDeposit();
    expect($deposit->invoice())->toBeInstanceOf(BelongsTo::class);

    $migrationRun = new MigrationRun();
    expect($migrationRun->template())->toBeInstanceOf(BelongsTo::class);
});

it('covers SetCreditControl action branches including negative limit, missing customer, new and existing accounts', function (): void {
    $action = app(SetCreditControl::class);

    expect(fn () => $action->handle(1, -10.0))
        ->toThrow(InvalidArgumentException::class, 'Credit limit cannot be negative.');

    expect(fn () => $action->handle(999999, 100.0))
        ->toThrow(InvalidReceivable::class, 'Credit control can only be configured for an existing customer.');

    $entity = LegalEntity::create([
        'name' => 'Credit Control Entity',
        'currency_code' => 'USD',
    ]);
    $customer = Party::create([
        'legal_entity_id' => $entity->id,
        'type' => PartyType::Customer,
        'name' => 'Credit Customer',
    ]);

    // First call creates the ReceivableAccount via EnsureAccount
    $account = $action->handle($customer->id, 5000.0, true, 'Initial review');
    expect($account)->toBeInstanceOf(ReceivableAccount::class)
        ->and((float) $account->credit_limit)->toBe(5000.0)
        ->and($account->credit_hold)->toBeTrue()
        ->and($account->hold_reason)->toBe('Initial review');

    // Second call updates existing locked account
    $updated = $action->handle($customer->id, 7500.0, false, 'Cleared');
    expect($updated->id)->toBe($account->id)
        ->and((float) $updated->credit_limit)->toBe(7500.0)
        ->and($updated->credit_hold)->toBeFalse()
        ->and($updated->hold_reason)->toBe('Cleared');
});

it('covers ValidateOpeningBalances including stale locked batch, unbalanced, duplicate, and success paths', function (): void {
    $createBatch = app(CreateOpeningBalanceBatch::class);
    $validateBatch = app(ValidateOpeningBalances::class);

    // 1. Unbalanced batch -> fails and marks Failed / Exception, then re-validates
    $unbalanced = $createBatch->handle([
        'team_id' => 501,
        'batch_ref' => 'HOST-OB-UNBAL',
        'migration_date' => '2026-01-01',
        'currency' => 'USD',
    ], [
        ['balance_type' => 'account', 'reference_id' => '100', 'debit_amount' => 100.00],
        ['balance_type' => 'account', 'reference_id' => '200', 'credit_amount' => 60.00],
    ]);

    expect(fn () => $validateBatch->handle($unbalanced))
        ->toThrow(InvalidOpeningBalance::class, 'USD is out of balance by 40');

    $unbalanced->refresh();
    expect($unbalanced->status)->toBe(OpeningBalanceStatus::Failed)
        ->and($unbalanced->entries()->where('status', EntryStatus::Exception)->count())->toBe(2);

    $unbalanced->entries()->where('reference_id', '200')->update(['credit_amount' => 100.00]);
    $validated = $validateBatch->handle($unbalanced);
    expect($validated->status)->toBe(OpeningBalanceStatus::Validated)
        ->and($validated->entries->every(fn ($entry): bool => $entry->status === EntryStatus::Valid))->toBeTrue();

    // 2. Outer guard: already validated batch
    expect(fn () => $validateBatch->handle($validated))
        ->toThrow(InvalidOpeningBalance::class, 'Only draft or failed batches can be validated.');

    // 3. Inner locked guard: stale in-memory Draft batch whose DB status changed to Validated
    $staleBatch = $createBatch->handle([
        'team_id' => 501,
        'batch_ref' => 'HOST-OB-STALE',
        'migration_date' => '2026-01-01',
        'currency' => 'USD',
    ], [
        ['balance_type' => 'account', 'reference_id' => '110', 'debit_amount' => 50.00],
        ['balance_type' => 'account', 'reference_id' => '210', 'credit_amount' => 50.00],
    ]);

    OpeningBalanceBatch::query()->whereKey($staleBatch->id)->update(['status' => OpeningBalanceStatus::Validated->value]);
    expect(fn () => $validateBatch->handle($staleBatch))
        ->toThrow(InvalidOpeningBalance::class, 'Only draft or failed batches can be validated.');

    // 4. Duplicate balance references
    $dupBatch = $createBatch->handle([
        'team_id' => 501,
        'batch_ref' => 'HOST-OB-DUP',
        'migration_date' => '2026-01-01',
        'currency' => 'USD',
    ], [
        ['balance_type' => 'account', 'reference_id' => '300', 'debit_amount' => 50.00],
        ['balance_type' => 'account', 'reference_id' => '300', 'credit_amount' => 50.00],
    ]);

    expect(fn () => $validateBatch->handle($dupBatch))
        ->toThrow(InvalidOpeningBalance::class, 'Duplicate balance references were supplied.');
});

it('covers CreateTaxRule and CalculateTax across all validation and treatment branches', function (): void {
    $create = app(CreateTaxRule::class);
    $calculate = app(CalculateTax::class);

    $valid = [
        'code' => 'HOST-VAT-20',
        'name' => 'Host Standard VAT',
        'tax_type' => 'vat',
        'jurisdiction_code' => 'GB',
        'rate' => 20,
        'effective_from' => '2026-01-01 00:00:00',
    ];

    foreach (['code', 'name', 'tax_type', 'effective_from'] as $required) {
        expect(fn () => $create->handle(array_diff_key($valid, [$required => true])))
            ->toThrow(InvalidTaxRule::class, "Tax rule field [{$required}] is required.");
    }

    expect(fn () => $create->handle([...$valid, 'rate' => -1]))
        ->toThrow(InvalidTaxRule::class, 'Tax rate must be between 0 and 100.')
        ->and(fn () => $create->handle([...$valid, 'rate' => 101]))
        ->toThrow(InvalidTaxRule::class, 'Tax rate must be between 0 and 100.')
        ->and(fn () => $create->handle([...$valid, 'rounding_scale' => -1]))
        ->toThrow(InvalidTaxRule::class, 'Rounding scale must be between 0 and 6.')
        ->and(fn () => $create->handle([...$valid, 'rounding_scale' => 7]))
        ->toThrow(InvalidTaxRule::class, 'Rounding scale must be between 0 and 6.')
        ->and(fn () => $create->handle([...$valid, 'effective_until' => '2025-12-31']))
        ->toThrow(InvalidTaxRule::class, 'Effective end must not precede effective start.');

    $exclusiveRule = $create->handle($valid);

    expect(fn () => $create->handle($valid))
        ->toThrow(InvalidTaxRule::class, 'A tax rule already exists for this code, jurisdiction, and effective date.')
        ->and(fn () => $calculate->handle($exclusiveRule, -0.01))
        ->toThrow(InvalidTaxRule::class, 'Taxable amount must not be negative.');

    $inclusiveRule = $create->handle([
        'code' => 'HOST-GST-INC',
        'name' => 'Inclusive GST',
        'tax_type' => 'gst',
        'rate' => 10,
        'treatment' => TaxTreatment::Inclusive,
        'effective_from' => '2026-01-01',
    ]);

    $exemptRule = $create->handle([
        'code' => 'HOST-VAT-EX',
        'name' => 'Exempt VAT',
        'tax_type' => 'vat',
        'rate' => 0,
        'treatment' => TaxTreatment::Exempt,
        'effective_from' => '2026-01-01',
    ]);

    expect($calculate->handle($exclusiveRule, 100.0))->toBe([
        'net' => 100.0,
        'tax' => 20.0,
        'gross' => 120.0,
        'rate' => 20.0,
        'rounding_scale' => 2,
    ])->and($calculate->handle($inclusiveRule, 110.0))->toBe([
        'net' => 100.0,
        'tax' => 10.0,
        'gross' => 110.0,
        'rate' => 10.0,
        'rounding_scale' => 2,
    ])->and($calculate->handle($exemptRule, 50.0))->toBe([
        'net' => 50.0,
        'tax' => 0.0,
        'gross' => 50.0,
        'rate' => 0.0,
        'rounding_scale' => 2,
    ]);
});

it('covers RevolutController redirectToRevolut and handleCallback', function (): void {
    $this->withoutMiddleware(ThrottleRequests::class);
    [$user, $team] = makeHostAccountingRevolutUserAndTeam();

    $revolut = Mockery::mock(RevolutService::class);
    $revolut->shouldReceive('getAuthorizationUrl')
        ->once()
        ->with(Mockery::type('string'))
        ->andReturn('https://sandbox-business.revolut.com/app-confirm?state=abc');
    $revolut->shouldReceive('getAuthorizationUrl')
        ->once()
        ->andThrow(new Exception('Auth URL error'));
    $revolut->shouldReceive('exchangeAuthorizationCode')
        ->once()
        ->with('code-ok-1')
        ->andReturn([
            'access_token' => 'rev-access-1',
            'refresh_token' => 'rev-refresh-1',
            'expires_in' => 2400,
        ]);
    $revolut->shouldReceive('exchangeAuthorizationCode')
        ->once()
        ->with('code-ok-2')
        ->andReturn([
            'access_token' => 'rev-access-2',
        ]);
    $revolut->shouldReceive('exchangeAuthorizationCode')
        ->once()
        ->with('code-bad')
        ->andThrow(new Exception('Token exchange error'));
    $this->app->instance(RevolutService::class, $revolut);

    Sanctum::actingAs($user);

    $this->getJson('/api/revolut/authorize')
        ->assertOk()
        ->assertJson([
            'success' => true,
            'authorization_url' => 'https://sandbox-business.revolut.com/app-confirm?state=abc',
        ]);

    expect(Cache::get('revolut_oauth_state:'.$user->id))->not->toBeNull();

    $this->getJson('/api/revolut/authorize')
        ->assertStatus(500)
        ->assertJson(['success' => false]);

    $this->postJson('/api/revolut/callback', [])
        ->assertStatus(400)
        ->assertJson(['success' => false, 'message' => 'Authorization code missing']);

    $this->postJson('/api/revolut/callback', ['code' => 'code-1', 'state' => 'wrong'])
        ->assertStatus(422)
        ->assertJson(['success' => false, 'message' => 'Invalid OAuth state']);

    Cache::put('revolut_oauth_state:'.$user->id, 'expected-state', 60);
    $this->postJson('/api/revolut/callback', ['code' => 'code-1', 'state' => 'other-state'])
        ->assertStatus(422)
        ->assertJson(['success' => false, 'message' => 'Invalid OAuth state']);

    Cache::put('revolut_oauth_state:'.$user->id, 'valid-state-1', 60);
    $this->postJson('/api/revolut/callback', ['code' => 'code-ok-1', 'state' => 'valid-state-1'])
        ->assertCreated()
        ->assertJson([
            'success' => true,
            'connection' => ['institution_name' => 'Revolut Business', 'status' => 'active'],
        ]);

    Cache::put('revolut_oauth_state:'.$user->id, 'valid-state-2', 60);
    $this->postJson('/api/revolut/callback', ['code' => 'code-ok-2', 'state' => 'valid-state-2'])
        ->assertCreated();

    expect(BankConnection::where('team_id', $team->id)->where('bank_id', 'revolut')->count())->toBe(2);

    Cache::put('revolut_oauth_state:'.$user->id, 'valid-state-3', 60);
    $this->postJson('/api/revolut/callback', ['code' => 'code-bad', 'state' => 'valid-state-3'])
        ->assertStatus(500)
        ->assertJson(['success' => false]);
});

it('covers RevolutController listConnections, getAccounts, syncTransactions, and removeConnection', function (): void {
    $this->withoutMiddleware(ThrottleRequests::class);
    [$user, $team] = makeHostAccountingRevolutUserAndTeam();
    [, $otherTeam] = makeHostAccountingRevolutUserAndTeam();

    $connNoLastSync = BankConnection::factory()->active()->create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'bank_id' => 'revolut',
        'institution_name' => 'Revolut Business',
        'last_synced_at' => null,
    ]);
    $connWithLastSync = BankConnection::factory()->active()->create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'bank_id' => 'revolut',
        'last_synced_at' => now()->subDay(),
    ]);
    $inactiveConn = BankConnection::factory()->disconnected()->create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'bank_id' => 'revolut',
    ]);
    $otherConn = BankConnection::factory()->active()->create([
        'user_id' => $user->id,
        'team_id' => $otherTeam->id,
        'bank_id' => 'revolut',
    ]);

    $revolut = Mockery::mock(RevolutService::class);
    $revolut->shouldReceive('getAccounts')
        ->once()
        ->andReturn([
            [
                'id' => 'rev-acc-1',
                'name' => 'GBP Main',
                'type' => 'current',
                'balance' => 2500.75,
                'currency' => 'GBP',
            ],
        ]);
    $revolut->shouldReceive('getAccounts')
        ->once()
        ->andThrow(new Exception('Accounts error'));
    $revolut->shouldReceive('getTransactions')
        ->once()
        ->andReturn([
            [
                'id' => 'host-rtx-1',
                'type' => 'transfer',
                'state' => 'pending',
                'completed_at' => '2026-07-10T10:00:00Z',
                'reference' => 'Invoice 1',
                'legs' => [['amount' => -120.50]],
            ],
            [
                'id' => 'host-rtx-2',
                'type' => 'card_payment',
                'state' => 'completed',
                'created_at' => '2026-07-11T10:00:00Z',
                'merchant' => ['name' => 'Coffee Co'],
                'legs' => [['amount' => 50.00]],
            ],
            [
                'id' => 'host-rtx-3',
                'type' => 'custom_type',
                'state' => 'declined',
                'merchant' => ['category' => 'Restaurants'],
                'legs' => [['amount' => -15.00]],
            ],
            [
                'id' => 'host-rtx-4',
                'type' => 'unknown_type',
                'state' => 'failed',
                'legs' => [['amount' => -20.00]],
            ],
            [
                'id' => 'host-rtx-5',
                'type' => 'fx',
                'state' => 'reverted',
                'legs' => [['amount' => 30.00]],
            ],
            [
                'id' => 'host-rtx-6',
                'type' => 'fee',
                'state' => 'unexpected_state',
                'legs' => [['amount' => -5.00]],
            ],
        ]);
    $revolut->shouldReceive('getTransactions')->once()->andReturn([]);
    $revolut->shouldReceive('getTransactions')->once()->andThrow(new Exception('Tx sync error'));
    $this->app->instance(RevolutService::class, $revolut);

    Sanctum::actingAs($user);

    // listConnections success + error
    $this->getJson('/api/revolut/connections')
        ->assertOk()
        ->assertJson(['success' => true]);

    $throwList = true;
    BankConnection::retrieved(function () use (&$throwList): void {
        if ($throwList) {
            $throwList = false;
            throw new Exception('Revolut list DB error');
        }
    });

    $this->getJson('/api/revolut/connections')
        ->assertStatus(500)
        ->assertJson(['success' => false, 'message' => 'Failed to retrieve connections']);

    // getAccounts
    $this->getJson("/api/revolut/connections/{$otherConn->id}/accounts")->assertForbidden();
    $this->getJson("/api/revolut/connections/{$inactiveConn->id}/accounts")->assertStatus(400);
    $this->getJson("/api/revolut/connections/{$connNoLastSync->id}/accounts")
        ->assertOk()
        ->assertJson(['success' => true])
        ->assertJsonCount(1, 'accounts');
    expect(BankAccountBalance::where('bank_connection_id', $connNoLastSync->id)->count())->toBe(1);
    $this->getJson("/api/revolut/connections/{$connNoLastSync->id}/accounts")
        ->assertStatus(500)
        ->assertJson(['success' => false]);

    // syncTransactions
    $this->postJson("/api/revolut/connections/{$otherConn->id}/sync")->assertForbidden();
    $this->postJson("/api/revolut/connections/{$inactiveConn->id}/sync")->assertStatus(400);
    $this->postJson("/api/revolut/connections/{$connNoLastSync->id}/sync")
        ->assertOk()
        ->assertJson(['success' => true, 'summary' => ['total_processed' => 6]]);
    expect(Transaction::where('external_id', 'host-rtx-1')->first()?->status)->toBe('pending')
        ->and(Transaction::where('external_id', 'host-rtx-3')->first()?->category)->toBe('restaurants')
        ->and(Transaction::where('external_id', 'host-rtx-4')->first()?->category)->toBe('uncategorized')
        ->and(BankFeedTransaction::where('bank_connection_id', $connNoLastSync->id)->count())->toBe(6);

    $this->postJson("/api/revolut/connections/{$connWithLastSync->id}/sync")
        ->assertOk()
        ->assertJson(['success' => true, 'summary' => ['total_processed' => 0]]);
    $this->postJson("/api/revolut/connections/{$connNoLastSync->id}/sync")
        ->assertStatus(500)
        ->assertJson(['success' => false]);

    // removeConnection
    $this->deleteJson("/api/revolut/connections/{$otherConn->id}")->assertForbidden();
    $this->deleteJson("/api/revolut/connections/{$connNoLastSync->id}")
        ->assertOk()
        ->assertJson(['success' => true]);
    expect($connNoLastSync->fresh()->status)->toBe('disconnected');

    $throwUpdate = true;
    BankConnection::updating(function () use (&$throwUpdate): void {
        if ($throwUpdate) {
            $throwUpdate = false;
            throw new Exception('Update failed');
        }
    });

    $this->deleteJson("/api/revolut/connections/{$connWithLastSync->id}")
        ->assertStatus(500)
        ->assertJson(['success' => false]);
});

it('covers RevolutController sendPayment and sendBulkPayment', function (): void {
    $this->withoutMiddleware(ThrottleRequests::class);
    [$user, $team] = makeHostAccountingRevolutUserAndTeam();
    [, $otherTeam] = makeHostAccountingRevolutUserAndTeam();

    $activeConn = BankConnection::factory()->active()->create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'bank_id' => 'revolut',
    ]);
    $inactiveConn = BankConnection::factory()->disconnected()->create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'bank_id' => 'revolut',
    ]);
    $otherConn = BankConnection::factory()->active()->create([
        'user_id' => $user->id,
        'team_id' => $otherTeam->id,
        'bank_id' => 'revolut',
    ]);

    $revolut = Mockery::mock(RevolutService::class);
    $revolut->shouldReceive('sendPayment')->once()->andReturn(['id' => 'host-pay-1', 'state' => 'completed']);
    $revolut->shouldReceive('sendPayment')->once()->andThrow(new Exception('Revolut declined'));
    $revolut->shouldReceive('sendBulkPayment')->once()->andReturn(['id' => 'host-draft-1']);
    $revolut->shouldReceive('sendBulkPayment')->once()->andThrow(new Exception('Bulk failed'));
    $this->app->instance(RevolutService::class, $revolut);

    Sanctum::actingAs($user, ['payments:write']);

    $basePayload = [
        'account_id' => 'acc-1',
        'receiver' => ['counterparty_id' => 'cp-1'],
        'amount' => 50.00,
        'currency' => 'GBP',
        'reference' => 'Invoice #101',
    ];

    $this->postJson("/api/revolut/connections/{$activeConn->id}/pay", [])->assertStatus(422);
    $this->postJson("/api/revolut/connections/{$otherConn->id}/pay", array_merge($basePayload, ['idempotency_key' => 'host-key-unauth']))->assertForbidden();
    $this->postJson("/api/revolut/connections/{$inactiveConn->id}/pay", array_merge($basePayload, ['idempotency_key' => 'host-key-inactive']))->assertStatus(400);

    $this->postJson("/api/revolut/connections/{$activeConn->id}/pay", array_merge($basePayload, ['idempotency_key' => 'host-key-sent-1']))
        ->assertCreated()
        ->assertJson([
            'success' => true,
            'message' => 'Payment sent successfully',
            'payment' => ['id' => 'host-pay-1'],
        ]);

    $this->postJson("/api/revolut/connections/{$activeConn->id}/pay", array_merge($basePayload, ['idempotency_key' => 'host-key-sent-1']))
        ->assertStatus(409)
        ->assertJson(['success' => false]);

    $this->postJson("/api/revolut/connections/{$activeConn->id}/pay", array_merge($basePayload, ['idempotency_key' => 'host-key-fail-1']))
        ->assertStatus(500)
        ->assertJson(['success' => false, 'message' => 'Payment failed to send']);

    Notification::fake();
    Role::firstOrCreate(['name' => 'finance_manager', 'guard_name' => 'web']);

    ApprovalRule::create([
        'team_id' => $team->id,
        'approvable_type' => 'OutboundPayment',
        'min_amount' => 100.00,
        'steps' => ['finance_manager'],
        'is_active' => true,
    ]);

    $this->postJson("/api/revolut/connections/{$activeConn->id}/pay", array_merge($basePayload, [
        'idempotency_key' => 'host-key-pending-1',
        'amount' => 250.00,
    ]))
        ->assertStatus(202)
        ->assertJson([
            'success' => true,
            'message' => 'Payment queued for approval',
            'status' => OutboundPayment::STATUS_PENDING_APPROVAL,
        ]);

    $throwPayment = true;
    OutboundPayment::creating(function () use (&$throwPayment): void {
        if ($throwPayment) {
            $throwPayment = false;
            throw new Exception('Payment DB crash');
        }
    });

    $this->postJson("/api/revolut/connections/{$activeConn->id}/pay", array_merge($basePayload, ['idempotency_key' => 'host-key-crash-1']))
        ->assertStatus(500)
        ->assertJson(['success' => false]);

    // sendBulkPayment
    $bulkPayload = [
        'title' => 'Monthly Payroll',
        'schedule_for' => '2026-08-01',
        'payments' => [
            [
                'account_id' => 'acc-1',
                'receiver' => ['counterparty_id' => 'cp-1'],
                'amount' => 100.00,
                'currency' => 'GBP',
                'reference' => 'Salary',
            ],
        ],
    ];

    $this->postJson("/api/revolut/connections/{$activeConn->id}/bulk-pay", [])->assertStatus(422);
    $this->postJson("/api/revolut/connections/{$otherConn->id}/bulk-pay", array_merge($bulkPayload, ['idempotency_key' => 'host-bk-1']))->assertForbidden();
    $this->postJson("/api/revolut/connections/{$inactiveConn->id}/bulk-pay", array_merge($bulkPayload, ['idempotency_key' => 'host-bk-2']))->assertStatus(400);

    $this->postJson("/api/revolut/connections/{$activeConn->id}/bulk-pay", array_merge($bulkPayload, ['idempotency_key' => 'host-bk-ok']))
        ->assertCreated()
        ->assertJson(['success' => true, 'payment_draft' => ['id' => 'host-draft-1']]);

    $this->postJson("/api/revolut/connections/{$activeConn->id}/bulk-pay", array_merge($bulkPayload, ['idempotency_key' => 'host-bk-ok']))
        ->assertStatus(409)
        ->assertJson(['success' => false]);

    $this->postJson("/api/revolut/connections/{$activeConn->id}/bulk-pay", array_merge($bulkPayload, ['idempotency_key' => 'host-bk-fail']))
        ->assertStatus(500)
        ->assertJson(['success' => false]);
});

it('covers accounting-accounts-receivable-api provider, policy, controller, and resources', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(AccountsReceivableApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(AccountsReceivableApiServiceProvider::class);

    $policy = new AccountsReceivablePolicy();
    $allowUser = new class() extends User
    {
        public int $current_team_id = 1;

        public function tokenCan(string $a): bool
        {
            return true;
        }
    };
    $denyUser = new class() extends User
    {
        public int $current_team_id = 2;

        public function tokenCan(string $a): bool
        {
            return false;
        }
    };
    $record = (object) ['team_id' => 1];

    expect($policy->viewAny($allowUser))->toBeTrue()
        ->and($policy->viewAny($denyUser))->toBeFalse()
        ->and($policy->viewAny(null))->toBeFalse()
        ->and($policy->view($allowUser, $record))->toBeTrue()
        ->and($policy->view($denyUser, $record))->toBeFalse()
        ->and($policy->view(null, $record))->toBeFalse()
        ->and($policy->create($allowUser))->toBeTrue()
        ->and($policy->create($denyUser))->toBeFalse()
        ->and($policy->create(null))->toBeFalse()
        ->and($policy->update($allowUser, $record))->toBeTrue()
        ->and($policy->update($denyUser, $record))->toBeFalse()
        ->and($policy->update(null, $record))->toBeFalse();

    expect((new ReceivableOpenItemResource(null))->toArray(request()))->toBe([])
        ->and((new ReceivableReceiptResource(null))->toArray(request()))->toBe([])
        ->and((new ReceivableDisputeResource(null))->toArray(request()))->toBe([]);

    $entity = LegalEntity::create([
        'name' => 'Host AR API Entity',
        'currency_code' => 'USD',
    ]);
    $customer = Party::create([
        'legal_entity_id' => $entity->id,
        'type' => PartyType::Customer,
        'name' => 'Host Acme Customer',
    ]);

    $openItemRes = $this->postJson('/api/v1/accounting/accounts-receivable', [
        'party_id' => $customer->id,
        'reference' => 'HOST-INV-001',
        'issued_on' => '2026-01-10',
        'due_on' => '2026-02-10',
        'original_amount' => 500.00,
        'currency' => 'USD',
        'metadata' => ['source' => 'api'],
    ]);
    expect($openItemRes->status())->toBeLessThan(300);
    $openItemId = $openItemRes->json('data.id');

    $this->getJson('/api/v1/accounting/accounts-receivable')->assertOk();
    $this->getJson("/api/v1/accounting/accounts-receivable?party_id={$customer->id}")->assertOk();
    $openItemModel = ReceivableOpenItem::query()->findOrFail($openItemId);
    $arController = app(AccountsReceivableController::class);
    expect($arController->show($openItemModel)->toArray(request()))->toBeArray();
    $this->getJson("/api/v1/accounting/accounts-receivable/statement/{$customer->id}?from=2026-01-01&to=2026-12-31")->assertOk();
    $this->getJson("/api/v1/accounting/accounts-receivable/aging?party_id={$customer->id}&as_of=2026-02-15")->assertOk();
    $this->getJson("/api/v1/accounting/accounts-receivable/balances/{$customer->id}")->assertOk();
    $this->getJson('/api/v1/accounting/accounts-receivable/reconciliation?control_balance=500')->assertOk();

    $receiptRes = $this->postJson('/api/v1/accounting/accounts-receivable/receipts', [
        'party_id' => $customer->id,
        'received_on' => '2026-01-20',
        'amount' => 200.00,
        'currency' => 'USD',
        'reference' => 'HOST-RCPT-001',
    ])->assertCreated();
    $receiptId = $receiptRes->json('data.id');

    $this->getJson("/api/v1/accounting/accounts-receivable/receipts?party_id={$customer->id}")->assertOk();

    $receiptModel = ReceivableReceipt::query()->findOrFail($receiptId);
    $applyRequest = Request::create("/api/v1/accounting/accounts-receivable/receipts/{$receiptId}/apply", 'POST', [
        'open_item_id' => $openItemId,
        'amount' => 150.00,
    ]);
    $applyRequest->setUserResolver(fn () => $user);
    expect($arController->apply($applyRequest, $receiptModel)->getStatusCode())->toBe(200);

    $disputeRes = $this->postJson('/api/v1/accounting/accounts-receivable/disputes', [
        'open_item_id' => $openItemId,
        'reason' => 'Damaged goods',
        'amount' => 50.00,
    ])->assertCreated();
    $disputeId = $disputeRes->json('data.id');

    $this->getJson('/api/v1/accounting/accounts-receivable/disputes?status=open')->assertOk();

    $disputeModel = ReceivableDispute::query()->findOrFail($disputeId);
    $resolveRequest = Request::create("/api/v1/accounting/accounts-receivable/disputes/{$disputeId}/resolve", 'POST', [
        'resolution' => 'Allowance granted',
        'accepted' => true,
    ]);
    $resolveRequest->setUserResolver(fn () => $user);
    expect($arController->resolve($resolveRequest, $disputeModel)->getStatusCode())->toBe(200);

    $this->postJson("/api/v1/accounting/accounts-receivable/customers/{$customer->id}/credit-control", [
        'credit_limit' => 10000.00,
        'credit_hold' => false,
        'hold_reason' => 'Approved',
    ])->assertOk();
});

it('covers accounting-estimates-and-quotes-api provider, controllers, and resource', function (): void {
    [$user, $team] = makeHostAccountingRevolutUserAndTeam();
    [$otherUser, $otherTeam] = makeHostAccountingRevolutUserAndTeam();
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(EstimatesAndQuotesApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(EstimatesAndQuotesApiServiceProvider::class);

    $storeRes = $this->postJson('/api/v1/accounting/estimates-and-quotes', [
        'legal_entity_id' => 1,
        'customer_ref' => 'HOST-CUST-1',
        'quote_ref' => 'HOST-QT-100',
        'name' => 'ERP Implementation',
        'currency' => 'USD',
        'issue_date' => '2026-01-10',
        'expires_on' => '2026-12-31',
        'terms' => 'Net 30',
    ]);
    expect($storeRes->status())->toBeLessThan(500);
    $estimateId = $storeRes->json('data.id');
    expect($estimateId)->not->toBeNull();

    $this->getJson('/api/v1/accounting/estimates-and-quotes?legal_entity_id=1&status=draft')->assertOk();
    $this->getJson('/api/v1/accounting/estimates-and-quotes')->assertOk();
    $this->getJson('/api/v1/accounting/estimates-and-quotes?status=invalid-status')->assertStatus(422);

    $estimatesController = app(EstimatesController::class);
    $estimateModel = QuoteEstimate::query()->findOrFail($estimateId);

    expect($estimatesController->show($estimateModel)->toArray(request()))->toBeArray();

    $itemReq = Request::create("/api/v1/accounting/estimates-and-quotes/{$estimateId}/items", 'POST', [
        'item_ref' => 'SRV-1',
        'description' => 'Consulting services',
        'quantity' => 5,
        'unit_price' => 200,
        'tax_rate' => 10,
    ]);
    $itemReq->setUserResolver(fn () => $user);
    expect($estimatesController->item($itemReq, $estimateModel, app(AddEstimateItem::class))->toArray(request()))->toBeArray();

    $sendReq = Request::create("/api/v1/accounting/estimates-and-quotes/{$estimateId}/send", 'POST');
    $sendReq->setUserResolver(fn () => $user);
    expect($estimatesController->send($sendReq, $estimateModel->fresh(), app(SendEstimate::class))->toArray(request()))->toBeArray();

    $decideReq = Request::create("/api/v1/accounting/estimates-and-quotes/{$estimateId}/decide", 'POST', [
        'accepted' => true,
        'reason' => 'Looks good',
    ]);
    $decideReq->setUserResolver(fn () => $user);
    expect($estimatesController->decide($decideReq, $estimateModel->fresh(), app(DecideEstimate::class))->toArray(request()))->toBeArray();

    $convertReq = Request::create("/api/v1/accounting/estimates-and-quotes/{$estimateId}/convert", 'POST', [
        'converted_ref' => 'HOST-INV-100',
    ]);
    $convertReq->setUserResolver(fn () => $user);
    expect($estimatesController->convert($convertReq, $estimateModel->fresh(), app(ConvertEstimate::class))->toArray(request()))->toBeArray();

    $expirableRes = $this->postJson('/api/v1/accounting/estimates-and-quotes', [
        'legal_entity_id' => 1,
        'customer_ref' => 'HOST-CUST-2',
        'quote_ref' => 'HOST-QT-101',
        'name' => 'Expired Quote',
        'currency' => 'USD',
        'issue_date' => '2024-01-01',
        'expires_on' => '2024-01-15',
    ]);
    $expirableModel = QuoteEstimate::query()->findOrFail($expirableRes->json('data.id'));
    $expireReq = Request::create("/api/v1/accounting/estimates-and-quotes/{$expirableModel->id}/expire", 'POST');
    $expireReq->setUserResolver(fn () => $user);
    expect($estimatesController->expire($expireReq, $expirableModel, app(ExpireEstimate::class))->toArray(request()))->toBeArray();

    // EstimateController (api/v1/estimates)
    $customerId = DB::table('customers')->insertGetId([
        'team_id' => $team->id,
        'customer_name' => 'Jane',
        'customer_last_name' => 'Doe',
        'customer_address' => '123 Main St',
        'customer_email' => 'host.jane.estimate@example.test',
        'customer_phone' => '555-0101',
        'customer_city' => 'London',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $erpStore = $this->postJson('/api/v1/estimates', [
        'customer_id' => $customerId,
        'estimate_date' => '2026-01-10',
        'expiration_date' => '2026-02-10',
        'subtotal_amount' => 100,
        'tax_amount' => 20,
        'total_amount' => 120,
        'status' => 'draft',
        'notes' => 'Initial estimate',
        'terms' => 'Net 30',
    ])->assertCreated();
    $erpEstimateId = $erpStore->json('estimate_id') ?? $erpStore->json('id');

    $this->getJson('/api/v1/estimates')->assertOk();
    $this->getJson("/api/v1/estimates/{$erpEstimateId}")->assertOk();
    $this->putJson("/api/v1/estimates/{$erpEstimateId}", [
        'customer_id' => $customerId,
        'total_amount' => 150,
        'notes' => 'Updated estimate',
    ])->assertOk();

    Sanctum::actingAs($otherUser, ['*']);
    $this->getJson("/api/v1/estimates/{$erpEstimateId}")->assertForbidden();

    Sanctum::actingAs($user, ['*']);
    $this->deleteJson("/api/v1/estimates/{$erpEstimateId}")->assertOk();
});

it('covers accounting-financial-master-data-api provider, policies, requests, controllers, and resources', function (): void {
    $user = User::factory()->create(['current_team_id' => 1]);
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(FinancialMasterDataApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(FinancialMasterDataApiServiceProvider::class);

    foreach ([
        StorePartyDetailRequest::class,
        StorePartyRequest::class,
        StoreReferenceDataRequest::class,
        UpdatePartyRequest::class,
        UpdateReferenceDataRequest::class,
    ] as $requestClass) {
        $formReq = new $requestClass();
        expect($formReq->authorize())->toBeBool()
            ->and($formReq->rules())->toBeArray();
    }

    $policy = new PartyPolicy();
    $allowUser = new class() extends User
    {
        public int $current_team_id = 1;

        public function tokenCan(string $a): bool
        {
            return true;
        }
    };
    $denyUser = new class() extends User
    {
        public int $current_team_id = 2;

        public function tokenCan(string $a): bool
        {
            return false;
        }
    };
    $record = (new Party())->forceFill(['team_id' => 1, 'legal_entity_id' => 1]);
    expect($policy->viewAny($allowUser))->toBeTrue()
        ->and($policy->viewAny($denyUser))->toBeFalse()
        ->and($policy->viewAny(null))->toBeFalse()
        ->and($policy->view($allowUser, $record))->toBeTrue()
        ->and($policy->view($denyUser, $record))->toBeFalse()
        ->and($policy->view(null, $record))->toBeFalse()
        ->and($policy->create($allowUser))->toBeTrue()
        ->and($policy->create($denyUser))->toBeFalse()
        ->and($policy->create(null))->toBeFalse()
        ->and($policy->update($allowUser, $record))->toBeTrue()
        ->and($policy->update($denyUser, $record))->toBeFalse()
        ->and($policy->update(null, $record))->toBeFalse()
        ->and($policy->delete($allowUser, $record))->toBeTrue()
        ->and($policy->delete($denyUser, $record))->toBeFalse()
        ->and($policy->delete(null, $record))->toBeFalse();

    $legalEntityId = DB::table('accounting_legal_entities')->insertGetId([
        'name' => 'Host Master Data Corp',
        'registration_number' => 'HOST-MD-001',
        'currency_code' => 'USD',
        'accounting_basis' => 'accrual',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $base = '/api/v1/accounting/financial-master-data';

    // PartyController
    $party1Res = $this->postJson("{$base}/parties", [
        'legal_entity_id' => $legalEntityId,
        'type' => 'customer',
        'reference' => 'HOST-CUST-001',
        'name' => 'Host Acme Customer',
        'email' => 'host.acme@example.test',
    ])->assertCreated();
    $party1Id = $party1Res->json('data.id');

    $this->postJson("{$base}/parties", [
        'legal_entity_id' => $legalEntityId,
        'type' => 'customer',
        'name' => 'Host Acme Customer',
    ])->assertStatus(422);

    $party2Res = $this->postJson("{$base}/parties", [
        'legal_entity_id' => $legalEntityId,
        'type' => 'customer',
        'reference' => 'HOST-CUST-002',
        'name' => 'Host Beta Customer',
    ])->assertCreated();
    $party2Id = $party2Res->json('data.id');

    $this->getJson("{$base}/parties?type=customer&legal_entity_id={$legalEntityId}")->assertOk();
    $this->getJson("{$base}/parties/{$party1Id}")->assertOk();
    $this->patchJson("{$base}/parties/{$party1Id}", ['phone' => '555-1234'])->assertOk();
    $this->patchJson("{$base}/parties/{$party2Id}", ['name' => 'Host Acme Customer'])->assertStatus(422);
    $this->deleteJson("{$base}/parties/{$party2Id}")->assertNoContent();

    // PartyDetailController: addresses, duplicate catch, bank-details, unknown
    $addrRes = $this->postJson("{$base}/parties/{$party1Id}/addresses", [
        'kind' => 'billing',
        'line_one' => '100 High St',
        'city' => 'London',
        'country_code' => 'GB',
        'is_primary' => true,
    ])->assertCreated();
    $addrId = $addrRes->json('data.id');
    $this->getJson("{$base}/parties/{$party1Id}/addresses")->assertOk();

    $throwAddrDup = true;
    Address::creating(function () use (&$throwAddrDup): void {
        if ($throwAddrDup) {
            $throwAddrDup = false;
            throw new DuplicateMasterRecord('Duplicate address');
        }
    });
    $this->postJson("{$base}/parties/{$party1Id}/addresses", [
        'kind' => 'billing',
        'line_one' => '100 High St',
        'city' => 'London',
        'country_code' => 'GB',
    ])->assertStatus(422);

    request()->setUserResolver(fn () => $user);
    expect((new PartyDetailController())->destroy((string) $party1Id, 'addresses', (string) $addrId)->getStatusCode())->toBe(204);

    $bankRes = $this->postJson("{$base}/parties/{$party1Id}/bank-details", [
        'label' => 'Primary Bank',
        'bank_name' => 'Barclays',
        'country_code' => 'GB',
        'currency_code' => 'GBP',
        'credential_reference' => 'vault://bank/1',
        'is_primary' => true,
    ])->assertCreated();
    $bankId = $bankRes->json('data.id');
    $this->getJson("{$base}/parties/{$party1Id}/bank-details")->assertOk();
    request()->setUserResolver(fn () => $user);
    expect((new PartyDetailController())->destroy((string) $party1Id, 'bank-details', (string) $bankId)->getStatusCode())->toBe(204);

    try {
        (new PartyDetailController())->index((string) $party1Id, 'unknown');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(404);
    }

    // ReferenceDataController: items-services, tax-profiles, payment-terms + duplicate update catch
    $refPayloads = [
        'items-services' => [
            'store' => ['legal_entity_id' => $legalEntityId, 'sku' => 'HOST-SKU-1', 'name' => 'Widget', 'kind' => 'item', 'sales_price' => 50],
            'update' => ['name' => 'Widget Pro'],
        ],
        'tax-profiles' => [
            'store' => ['legal_entity_id' => $legalEntityId, 'code' => 'HOST-VAT20', 'name' => 'Standard VAT', 'rate' => 20],
            'update' => ['name' => 'Standard VAT 20%'],
        ],
        'payment-terms' => [
            'store' => ['legal_entity_id' => $legalEntityId, 'code' => 'HOST-NET30', 'name' => 'Net 30', 'days' => 30],
            'update' => ['name' => 'Net 30 Days'],
        ],
    ];

    $refController = new ReferenceDataController();
    $saveRef = app(SaveReferenceData::class);

    foreach ($refPayloads as $resource => $payloads) {
        $createRes = $this->postJson("{$base}/{$resource}", $payloads['store'])->assertCreated();
        $recId = (string) $createRes->json('data.id');

        $this->postJson("{$base}/{$resource}", $payloads['store'])->assertStatus(422);
        $this->getJson("{$base}/{$resource}?legal_entity_id={$legalEntityId}")->assertOk();

        request()->setUserResolver(fn () => $user);
        expect($refController->show($resource, $recId)->toArray(request()))->toBeArray();

        $updateReq = UpdateReferenceDataRequest::create("{$base}/{$resource}/{$recId}", 'PATCH', $payloads['update']);
        $updateReq->setContainer($this->app);
        $updateReq->setUserResolver(fn () => $user);
        $route = new IlluminateRoute(['PATCH'], "{$base}/{$resource}/{record}", []);
        $route->bind($updateReq);
        $route->setParameter('resource', $resource);
        $updateReq->setRouteResolver(fn () => $route);
        $updateReq->validateResolved();
        expect($refController->update($updateReq, $resource, $recId, $saveRef)->toArray(request()))->toBeArray();

        if ($resource === 'items-services') {
            $dupUpdateReq = UpdateReferenceDataRequest::create("{$base}/{$resource}/{$recId}", 'PATCH', ['name' => 'Widget Dup']);
            $dupUpdateReq->setContainer($this->app);
            $dupUpdateReq->setUserResolver(fn () => $user);
            $dupRoute = new IlluminateRoute(['PATCH'], "{$base}/{$resource}/{record}", []);
            $dupRoute->bind($dupUpdateReq);
            $dupRoute->setParameter('resource', $resource);
            $dupUpdateReq->setRouteResolver(fn () => $dupRoute);
            $dupUpdateReq->validateResolved();

            $throwRefDup = true;
            ItemService::updating(function () use (&$throwRefDup): void {
                if ($throwRefDup) {
                    $throwRefDup = false;
                    throw new DuplicateMasterRecord('Duplicate item SKU');
                }
            });
            expect(fn () => $refController->update($dupUpdateReq, $resource, $recId, $saveRef))
                ->toThrow(ValidationException::class);
        }

        request()->setUserResolver(fn () => $user);
        expect($refController->destroy($resource, $recId)->getStatusCode())->toBe(204);
    }

    try {
        (new ReferenceDataController())->show('unknown', '1');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(404);
    }
});

it('covers accounting-sales-invoicing-api provider, policy, controllers, and resource', function (): void {
    [$user, $team] = makeHostAccountingRevolutUserAndTeam();
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(SalesInvoicingApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(SalesInvoicingApiServiceProvider::class);

    $entity = LegalEntity::query()->create([
        'name' => 'Host Invoicing Entity',
        'currency_code' => 'GBP',
        'accounting_basis' => 'accrual',
        'is_active' => true,
    ]);
    $party = Party::query()->create([
        'legal_entity_id' => $entity->id,
        'type' => PartyType::Customer,
        'reference' => 'HOST-CUST-100',
        'name' => 'Host Acme Customer',
    ]);

    $storePayload = [
        'party_id' => $party->id,
        'invoice_number' => 'HOST-SINV-001',
        'invoice_date' => '2026-02-01',
        'due_on' => '2026-03-01',
        'currency' => 'GBP',
        'lines' => [
            ['description' => 'Consulting', 'quantity' => 2, 'unit_price' => 500.00, 'discount_rate' => 0, 'tax_rate' => 20],
        ],
    ];

    $storeResponse = $this->postJson('/api/v1/accounting/sales-invoicing', $storePayload)->assertCreated();
    $invId = $storeResponse->json('data.id');

    // Trigger InvalidInvoice catch in store()
    $throwInv = true;
    SalesInvoice::creating(function () use (&$throwInv): void {
        if ($throwInv) {
            $throwInv = false;
            throw new InvalidInvoice('Simulated invoice creation failure');
        }
    });
    $this->postJson('/api/v1/accounting/sales-invoicing', array_merge($storePayload, ['invoice_number' => 'HOST-SINV-ERR']))
        ->assertUnprocessable();

    $invoiceModel = SalesInvoice::query()->findOrFail($invId);
    $policy = new SalesInvoicingPolicy();
    $allowUser = new class() extends User
    {
        public int $current_team_id = 1;

        public function tokenCan(string $a): bool
        {
            return true;
        }
    };
    $denyUser = new class() extends User
    {
        public int $current_team_id = 2;

        public function tokenCan(string $a): bool
        {
            return false;
        }
    };

    expect($policy->viewAny($allowUser))->toBeTrue()
        ->and($policy->viewAny($denyUser))->toBeFalse()
        ->and($policy->viewAny(null))->toBeFalse()
        ->and($policy->view($allowUser, $invoiceModel))->toBeTrue()
        ->and($policy->view($denyUser, $invoiceModel))->toBeFalse()
        ->and($policy->view(null, $invoiceModel))->toBeFalse()
        ->and($policy->create($allowUser))->toBeTrue()
        ->and($policy->create($denyUser))->toBeFalse()
        ->and($policy->create(null))->toBeFalse()
        ->and($policy->update($allowUser, $invoiceModel))->toBeTrue()
        ->and($policy->update($denyUser, $invoiceModel))->toBeFalse()
        ->and($policy->update(null, $invoiceModel))->toBeFalse()
        ->and($policy->delete($allowUser, $invoiceModel))->toBeTrue()
        ->and($policy->delete($denyUser, $invoiceModel))->toBeFalse()
        ->and($policy->delete(null, $invoiceModel))->toBeFalse();

    $this->getJson('/api/v1/accounting/sales-invoicing')->assertOk();
    $this->getJson("/api/v1/accounting/sales-invoicing/{$invId}")->assertOk();

    // Trigger InvalidInvoice catch branches on Draft invoice
    $this->postJson("/api/v1/accounting/sales-invoicing/{$invId}/finalize")->assertUnprocessable();
    $this->postJson("/api/v1/accounting/sales-invoicing/{$invId}/deposit", ['amount' => 100.00])->assertUnprocessable();
    $this->postJson("/api/v1/accounting/sales-invoicing/{$invId}/deliver")->assertUnprocessable();

    // Valid lifecycle: approve -> approve again (catch) -> finalize -> deposit -> deliver
    $this->postJson("/api/v1/accounting/sales-invoicing/{$invId}/approve")->assertOk();
    $this->postJson("/api/v1/accounting/sales-invoicing/{$invId}/approve")->assertUnprocessable();
    $this->postJson("/api/v1/accounting/sales-invoicing/{$invId}/finalize")->assertOk();
    $this->postJson("/api/v1/accounting/sales-invoicing/{$invId}/deposit", ['amount' => 200.00, 'reference' => 'HOST-DEP-1'])->assertOk();
    $this->postJson("/api/v1/accounting/sales-invoicing/{$invId}/deliver")->assertOk();

    // Destroy a draft invoice
    $draftRes = $this->postJson('/api/v1/accounting/sales-invoicing', array_merge($storePayload, ['invoice_number' => 'HOST-SINV-DRAFT']))->assertCreated();
    $draftId = $draftRes->json('data.id');
    $this->deleteJson("/api/v1/accounting/sales-invoicing/{$draftId}")->assertNoContent();

    // Legacy InvoiceController (/api/invoices)
    $customer = Customer::factory()->create(['team_id' => $team->id]);
    $legacyCreate = $this->postJson('/api/invoices', [
        'customer_id' => $customer->getKey(),
        'invoice_date' => '2026-02-01',
        'due_date' => '2026-03-01',
        'total_amount' => 250.00,
        'notes' => 'Legacy invoice',
    ])->assertCreated();
    $legacyId = $legacyCreate->json('invoice_id') ?? $legacyCreate->json('id');

    $this->getJson('/api/invoices')->assertOk();
    $this->getJson("/api/invoices/{$legacyId}")->assertOk();
    $this->putJson("/api/invoices/{$legacyId}", ['customer_id' => $customer->getKey(), 'notes' => 'Updated notes'])->assertOk();
    $this->deleteJson("/api/invoices/{$legacyId}")->assertOk();
});

it('covers accounting-supplier-bills-api provider, policy, controllers, and resource', function (): void {
    [$user, $team] = makeHostAccountingRevolutUserAndTeam();
    Sanctum::actingAs($user, ['*']);

    $provider = $this->app->register(SupplierBillsApiServiceProvider::class, force: true);
    $provider->boot();
    expect($provider)->toBeInstanceOf(SupplierBillsApiServiceProvider::class);

    $policy = new SupplierBillsPolicy();
    $allowUser = new class() extends User
    {
        public int $current_team_id = 1;

        public function tokenCan(string $a): bool
        {
            return true;
        }
    };
    $denyUser = new class() extends User
    {
        public int $current_team_id = 2;

        public function tokenCan(string $a): bool
        {
            return false;
        }
    };
    $stub = (object) ['id' => 1];

    expect($policy->viewAny($allowUser))->toBeTrue()
        ->and($policy->viewAny($denyUser))->toBeFalse()
        ->and($policy->viewAny(null))->toBeFalse()
        ->and($policy->view($allowUser, $stub))->toBeTrue()
        ->and($policy->view($denyUser, $stub))->toBeFalse()
        ->and($policy->view(null, $stub))->toBeFalse()
        ->and($policy->create($allowUser))->toBeTrue()
        ->and($policy->create($denyUser))->toBeFalse()
        ->and($policy->create(null))->toBeFalse()
        ->and($policy->update($allowUser, $stub))->toBeTrue()
        ->and($policy->update($denyUser, $stub))->toBeFalse()
        ->and($policy->update(null, $stub))->toBeFalse();

    $entity = LegalEntity::query()->create([
        'name' => 'Host Bills Entity',
        'currency_code' => 'GBP',
        'accounting_basis' => 'accrual',
        'is_active' => true,
    ]);
    $party = Party::query()->create([
        'legal_entity_id' => $entity->id,
        'type' => PartyType::Supplier,
        'reference' => 'HOST-SUP-100',
        'name' => 'Host Acme Supplier',
    ]);

    $bill1 = $this->postJson('/api/v1/accounting/supplier-bills', [
        'party_id' => $party->id,
        'bill_number' => 'HOST-SB-001',
        'bill_date' => '2026-02-01',
        'due_on' => '2026-03-01',
        'currency' => 'GBP',
        'lines' => [
            ['account_code' => '5000', 'description' => 'Hosting', 'quantity' => 1, 'unit_price' => 400.00, 'tax_rate' => 20],
        ],
    ])->assertSuccessful();
    $bill1Id = $bill1->json('data.id');

    $this->getJson("/api/v1/accounting/supplier-bills?party_id={$party->id}&status=draft")->assertOk();
    $this->patchJson("/api/v1/accounting/supplier-bills/{$bill1Id}", [
        'party_id' => $party->id,
        'bill_date' => '2026-02-01',
        'currency' => 'GBP',
        'notes' => 'Updated bill',
        'lines' => [
            ['account_code' => '5000', 'description' => 'Hosting Updated', 'quantity' => 1, 'unit_price' => 450.00, 'tax_rate' => 20],
        ],
    ])->assertOk();

    $this->postJson("/api/v1/accounting/supplier-bills/{$bill1Id}/documents", [
        'path' => 'bills/host-sb-001.pdf',
        'original_name' => 'host-sb-001.pdf',
        'mime_type' => 'application/pdf',
        'sha256' => str_repeat('a', 64),
    ])->assertOk();

    $this->postJson("/api/v1/accounting/supplier-bills/{$bill1Id}/approve")->assertOk();

    $this->postJson("/api/v1/accounting/supplier-bills/{$bill1Id}/matches", [
        'match_type' => 'po',
        'matched_type' => 'purchase_order',
        'matched_id' => 'PO-001',
        'quantity' => 1,
        'amount' => 450.00,
    ])->assertOk();

    $this->postJson("/api/v1/accounting/supplier-bills/{$bill1Id}/post")->assertOk();

    $this->postJson("/api/v1/accounting/supplier-bills/{$bill1Id}/credits", [
        'amount' => 50.00,
        'currency' => 'GBP',
        'reason' => 'SLA credit',
        'reference' => 'HOST-CR-001',
    ])->assertOk();

    $this->getJson("/api/v1/accounting/supplier-bills/{$bill1Id}")->assertOk();
    $this->getJson("/api/v1/accounting/supplier-bills/duplicates?party_id={$party->id}&bill_number=HOST-SB-001")->assertOk();
    $this->getJson("/api/v1/accounting/supplier-bills/aging?party_id={$party->id}")->assertOk();
    $this->postJson("/api/v1/accounting/supplier-bills/{$bill1Id}/void", ['reason' => 'Duplicate entry'])->assertOk();

    $bill2 = $this->postJson('/api/v1/accounting/supplier-bills', [
        'party_id' => $party->id,
        'bill_number' => 'HOST-SB-002',
        'bill_date' => '2026-02-02',
        'currency' => 'GBP',
        'lines' => [
            ['description' => 'Rejected item', 'quantity' => 1, 'unit_price' => 100.00],
        ],
    ])->assertSuccessful();
    $bill2Id = $bill2->json('data.id');
    $this->postJson("/api/v1/accounting/supplier-bills/{$bill2Id}/reject", ['reason' => 'Incorrect amount'])->assertOk();

    // Legacy BillController (/api/bills)
    $vendor = Vendor::factory()->create(['team_id' => $team->id]);
    $legacyBill = $this->postJson('/api/bills', [
        'vendor_id' => $vendor->getKey(),
        'bill_date' => '2026-02-01',
        'due_date' => '2026-03-01',
        'total_amount' => 300.00,
        'reference_number' => 'HOST-REF-001',
    ])->assertCreated();
    $legacyBillId = $legacyBill->json('bill_id') ?? $legacyBill->json('id');

    $this->getJson('/api/bills')->assertOk();
    $this->getJson("/api/bills/{$legacyBillId}")->assertOk();
    $this->putJson("/api/bills/{$legacyBillId}", ['vendor_id' => $vendor->getKey(), 'notes' => 'Updated legacy bill'])->assertOk();
    $this->deleteJson("/api/bills/{$legacyBillId}")->assertOk();
});

it('covers RevolutWebhookController across invalid signature, all event branches, and exception handling', function (): void {
    $revolut = Mockery::mock(RevolutService::class);
    $revolut->shouldReceive('verifyWebhookSignature')
        ->once()
        ->with(Mockery::type('string'), 'bad-sig')
        ->andReturn(false);
    $revolut->shouldReceive('verifyWebhookSignature')
        ->times(3)
        ->with(Mockery::type('string'), 'good-sig')
        ->andReturn(true);
    $revolut->shouldReceive('verifyWebhookSignature')
        ->once()
        ->with(Mockery::type('string'), 'boom-sig')
        ->andThrow(new Exception('Webhook verification error'));
    $this->app->instance(RevolutService::class, $revolut);

    // 1. Invalid signature -> 401
    $this->postJson('/api/webhooks/revolut', ['event' => 'TransactionCreated'], ['Revolut-Signature' => 'bad-sig'])
        ->assertStatus(401)
        ->assertJson([
            'success' => false,
            'message' => 'Invalid signature',
        ]);

    // 2. TransactionCreated -> 200
    $this->postJson('/api/webhooks/revolut', [
        'event' => 'TransactionCreated',
        'data' => ['id' => 'rev-tx-100'],
    ], ['Revolut-Signature' => 'good-sig'])
        ->assertOk()
        ->assertJson([
            'success' => true,
            'message' => 'Webhook processed',
        ]);

    // 3. TransactionStateChanged -> 200
    $this->postJson('/api/webhooks/revolut', [
        'event' => 'TransactionStateChanged',
        'data' => ['id' => 'rev-tx-100', 'state' => 'completed'],
    ], ['Revolut-Signature' => 'good-sig'])
        ->assertOk()
        ->assertJson([
            'success' => true,
            'message' => 'Webhook processed',
        ]);

    // 4. Unhandled event -> default match arm -> 200
    $this->postJson('/api/webhooks/revolut', [
        'event' => 'CounterpartyAdded',
        'data' => ['id' => 'cp-1'],
    ], ['Revolut-Signature' => 'good-sig'])
        ->assertOk()
        ->assertJson([
            'success' => true,
            'message' => 'Webhook processed',
        ]);

    // 5. Exception during processing -> catch block -> 200 with success=false
    $this->postJson('/api/webhooks/revolut', [
        'event' => 'TransactionCreated',
    ], ['Revolut-Signature' => 'boom-sig'])
        ->assertOk()
        ->assertJson([
            'success' => false,
            'message' => 'Webhook processing failed',
        ]);
});

it('covers AccountController destroy InvalidAccount catch and GeneralLedgerController saveRecurring InvalidJournal catch', function (): void {
    [$user] = makeHostAccountingRevolutUserAndTeam();
    Sanctum::actingAs($user, ['*']);

    // 1. AccountController::destroy catching InvalidAccount
    $entity = LegalEntity::query()->create([
        'name' => 'Host COA Catch Entity',
        'currency_code' => 'GBP',
        'accounting_basis' => 'accrual',
        'is_active' => true,
    ]);
    $account = Account::query()->create([
        'legal_entity_id' => $entity->id,
        'code' => '1999',
        'name' => 'Catch Test Account',
        'type' => AccountType::Asset,
        'normal_balance' => NormalBalance::Debit,
        'is_active' => true,
    ]);

    $throwInvalidAccount = true;
    Account::updating(function () use (&$throwInvalidAccount): void {
        if ($throwInvalidAccount) {
            $throwInvalidAccount = false;
            throw new InvalidAccount('Simulated archive invalid account');
        }
    });

    $this->deleteJson("/api/v1/accounting/chart-of-accounts/accounts/{$account->id}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['account']);

    // 2. GeneralLedgerController::saveRecurring catching InvalidJournal
    $throwInvalidJournal = true;
    RecurringJournal::creating(function () use (&$throwInvalidJournal): void {
        if ($throwInvalidJournal) {
            $throwInvalidJournal = false;
            throw new InvalidJournal('Simulated recurring journal error');
        }
    });

    $this->postJson('/api/v1/accounting/general-ledger/recurring', [
        'book_id' => 1,
        'name' => 'Monthly Accrual',
        'frequency' => 'monthly',
        'next_run_on' => '2026-03-01',
        'lines' => [
            ['account_id' => 1, 'debit' => 100, 'credit' => 0],
            ['account_id' => 2, 'debit' => 0, 'credit' => 100],
        ],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['lines']);
});

it('covers remaining SalesInvoicing, SpreadsheetMigration, and TaxEvidence branches', function (): void {
    // CreateInvoice empty lines guard
    $createInvoice = app(CreateInvoice::class);
    expect(fn () => $createInvoice->handle([
        'invoice_number' => 'HOST-EMPTY-001',
        'invoice_date' => '2026-02-01',
        'currency' => 'GBP',
    ], []))->toThrow(InvalidInvoice::class, 'An invoice requires at least one line.');

    // Create a draft invoice and an approved invoice for SalesInvoicing actions & model coverage
    $draftInvoice = $createInvoice->handle([
        'invoice_number' => 'HOST-DRAFT-COV-001',
        'invoice_date' => '2026-02-01',
        'currency' => 'GBP',
    ], [
        ['description' => 'Service', 'quantity' => 1, 'unit_price' => 100.00],
    ]);

    $approvedInvoice = $createInvoice->handle([
        'invoice_number' => 'HOST-APPR-COV-001',
        'invoice_date' => '2026-02-01',
        'currency' => 'GBP',
    ], [
        ['description' => 'Service', 'quantity' => 1, 'unit_price' => 100.00],
    ]);
    $approvedInvoice->update(['status' => InvoiceStatus::Approved->value]);
    $approvedInvoice->refresh();

    // GenerateInvoicePdf unsupported format guard
    expect(fn () => app(GenerateInvoicePdf::class)->handle($approvedInvoice, 'html'))
        ->toThrow(InvalidInvoice::class, 'Only PDF output is supported by this boundary.');

    // SetInvoiceBranding non-draft guard
    expect(fn () => app(SetInvoiceBranding::class)->handle($approvedInvoice, ['logo' => 'brand.png']))
        ->toThrow(InvalidInvoice::class, 'Branding can only be changed on a draft invoice.');

    // SetRecurringSource non-draft guard and missing required field guard
    $setRecurringSource = app(SetRecurringSource::class);
    expect(fn () => $setRecurringSource->handle($approvedInvoice, ['source_type' => 'subscription', 'source_id' => 'sub_1']))
        ->toThrow(InvalidInvoice::class, 'Recurring source can only be changed on a draft invoice.')
        ->and(fn () => $setRecurringSource->handle($draftInvoice, ['source_type' => 'subscription']))
        ->toThrow(InvalidInvoice::class, 'Recurring source field [source_id] is required.');

    // SalesInvoice deleting non-draft guard & party() relation + SalesInvoiceLine invoice() relation
    expect(fn () => $approvedInvoice->delete())
        ->toThrow(LogicException::class, 'Approved and final invoices cannot be deleted.')
        ->and($approvedInvoice->party())->toBeInstanceOf(BelongsTo::class)
        ->and((new SalesInvoiceLine())->invoice())->toBeInstanceOf(BelongsTo::class);

    // CreateMigrationTemplate required field & non-array mapping guards
    $createTemplate = app(CreateMigrationTemplate::class);
    expect(fn () => $createTemplate->handle(['name' => '', 'entity' => 'customers', 'mapping' => ['a' => 'b']]))
        ->toThrow(InvalidMigration::class, 'Template field [name] is required.')
        ->and(fn () => $createTemplate->handle(['name' => 'Customers', 'entity' => 'customers', 'mapping' => 'not-an-array']))
        ->toThrow(InvalidMigration::class, 'Template mapping must be an array.');

    // ValidateMigration row_count < 1 guard
    expect(fn () => app(ValidateMigration::class)->handle(new MigrationRun(['row_count' => 0])))
        ->toThrow(InvalidMigration::class, 'A migration must contain at least one row.');

    // TaxEvidence taxRule() relation
    expect((new TaxEvidence())->taxRule())->toBeInstanceOf(BelongsTo::class);
});
