<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rules\Password;
use Laravel\Jetstream\Features as JetstreamFeatures;
use Liberu\Accounting\Erp\Actions\Fortify\PasswordValidationRules;
use Liberu\Accounting\Erp\Database\Factories\AccountFactory;
use Liberu\Accounting\Erp\Database\Factories\BankConnectionFactory;
use Liberu\Accounting\Erp\Database\Factories\BankStatementFactory;
use Liberu\Accounting\Erp\Database\Factories\BillFactory;
use Liberu\Accounting\Erp\Database\Factories\BudgetFactory;
use Liberu\Accounting\Erp\Database\Factories\CompanyFactory;
use Liberu\Accounting\Erp\Database\Factories\ConnectedAccountFactory;
use Liberu\Accounting\Erp\Database\Factories\CurrencyFactory;
use Liberu\Accounting\Erp\Database\Factories\CustomerFactory;
use Liberu\Accounting\Erp\Database\Factories\EstimateFactory;
use Liberu\Accounting\Erp\Database\Factories\ExchangeRateFactory;
use Liberu\Accounting\Erp\Database\Factories\InventoryItemFactory;
use Liberu\Accounting\Erp\Database\Factories\InvoiceFactory;
use Liberu\Accounting\Erp\Database\Factories\JournalEntryFactory;
use Liberu\Accounting\Erp\Database\Factories\MenuFactory;
use Liberu\Accounting\Erp\Database\Factories\ProjectFactory;
use Liberu\Accounting\Erp\Database\Factories\ReconciliationRuleFactory;
use Liberu\Accounting\Erp\Database\Factories\TaxRateFactory;
use Liberu\Accounting\Erp\Database\Factories\TransactionFactory;
use Liberu\Accounting\Erp\Database\Factories\UserFactory;
use Liberu\Accounting\Erp\Database\Factories\VendorFactory;
use Liberu\Accounting\Erp\Models\Account;
use Liberu\Accounting\Erp\Models\BankConnection;
use Liberu\Accounting\Erp\Models\BankStatement;
use Liberu\Accounting\Erp\Models\Bill;
use Liberu\Accounting\Erp\Models\Budget;
use Liberu\Accounting\Erp\Models\Company;
use Liberu\Accounting\Erp\Models\ConnectedAccount;
use Liberu\Accounting\Erp\Models\Currency;
use Liberu\Accounting\Erp\Models\Customer;
use Liberu\Accounting\Erp\Models\Estimate;
use Liberu\Accounting\Erp\Models\ExchangeRate;
use Liberu\Accounting\Erp\Models\InventoryItem;
use Liberu\Accounting\Erp\Models\Invoice;
use Liberu\Accounting\Erp\Models\JournalEntry;
use Liberu\Accounting\Erp\Models\Menu;
use Liberu\Accounting\Erp\Models\Project;
use Liberu\Accounting\Erp\Models\ReconciliationRule;
use Liberu\Accounting\Erp\Models\TaxRate;
use Liberu\Accounting\Erp\Models\Team;
use Liberu\Accounting\Erp\Models\Transaction;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\Erp\Models\Vendor;
use Liberu\Accounting\Erp\Services\Concerns\RequestsProviderTokens;
use Liberu\Foundation\Organizations\Database\Factories\TeamFactory as FoundationTeamFactory;

it('exercises every accounting-erp factory definition and state method', function (): void {
    $user = User::factory()->create();
    $unverified = User::factory()->unverified()->make();
    expect($user)->toBeInstanceOf(User::class)
        ->and($unverified->email_verified_at)->toBeNull();

    // UserFactory::withPersonalTeam when team features are disabled
    $previousFeatures = config('jetstream.features');
    config()->set('jetstream.features', []);
    $userNoTeam = User::factory()->withPersonalTeam()->make();
    expect($userNoTeam)->toBeInstanceOf(User::class);

    // UserFactory::withPersonalTeam when team features are enabled (covers inner state closure)
    $resolverProp = new ReflectionProperty(Factory::class, 'factoryNameResolver');
    $previousResolver = $resolverProp->getValue();
    try {
        config()->set('jetstream.features', [JetstreamFeatures::teams()]);
        Factory::guessFactoryNamesUsing(static function (string $modelName): string {
            if ($modelName === Team::class) {
                return FoundationTeamFactory::class;
            }

            return 'Database\\Factories\\'.class_basename($modelName).'Factory';
        });

        $userWithTeam = UserFactory::new()->withPersonalTeam()->create();
        expect($userWithTeam->ownedTeams()->count())->toBe(1);
    } finally {
        $resolverProp->setValue(null, $previousResolver);
        config()->set('jetstream.features', $previousFeatures);
    }

    // UserFactory::withConnectedAccount (covers inner state closure)
    $userWithConnected = UserFactory::new()->withConnectedAccount('github')->create();
    expect(ConnectedAccount::where('user_id', $userWithConnected->id)->where('provider', 'github')->exists())->toBeTrue();

    // All remaining 20 factories and their state methods
    expect(AccountFactory::new()->make())->toBeInstanceOf(Account::class)
        ->and(BankConnectionFactory::new()->make())->toBeInstanceOf(BankConnection::class)
        ->and(BankConnectionFactory::new()->active()->make()->status)->toBe('active')
        ->and(BankConnectionFactory::new()->disconnected()->make()->status)->toBe('disconnected')
        ->and(BankConnectionFactory::new()->withCursor()->make()->plaid_cursor)->toStartWith('cursor-')
        ->and(BankStatementFactory::new()->make())->toBeInstanceOf(BankStatement::class)
        ->and(BillFactory::new()->make())->toBeInstanceOf(Bill::class)
        ->and(BudgetFactory::new()->make())->toBeInstanceOf(Budget::class)
        ->and(CompanyFactory::new()->make())->toBeInstanceOf(Company::class)
        ->and(ConnectedAccountFactory::new()->make())->toBeInstanceOf(ConnectedAccount::class)
        ->and(CurrencyFactory::new()->make())->toBeInstanceOf(Currency::class)
        ->and(CurrencyFactory::new()->asDefault()->make()->is_default)->toBeTrue()
        ->and(CustomerFactory::new()->make())->toBeInstanceOf(Customer::class)
        ->and(EstimateFactory::new()->make())->toBeInstanceOf(Estimate::class)
        ->and(ExchangeRateFactory::new()->make())->toBeInstanceOf(ExchangeRate::class)
        ->and(InventoryItemFactory::new()->make())->toBeInstanceOf(InventoryItem::class)
        ->and(InvoiceFactory::new()->make())->toBeInstanceOf(Invoice::class)
        ->and(JournalEntryFactory::new()->make())->toBeInstanceOf(JournalEntry::class)
        ->and(JournalEntryFactory::new()->posted()->make()->is_posted)->toBeTrue()
        ->and(MenuFactory::new()->make())->toBeInstanceOf(Menu::class)
        ->and(ProjectFactory::new()->make())->toBeInstanceOf(Project::class)
        ->and(ReconciliationRuleFactory::new()->make())->toBeInstanceOf(ReconciliationRule::class)
        ->and(TaxRateFactory::new()->make())->toBeInstanceOf(TaxRate::class)
        ->and(TransactionFactory::new()->make())->toBeInstanceOf(Transaction::class)
        ->and(VendorFactory::new()->make())->toBeInstanceOf(Vendor::class);
});

it('returns fortify password validation rules from PasswordValidationRules trait', function (): void {
    $subject = new class()
    {
        use PasswordValidationRules;

        /**
         * @return array<int, mixed>
         */
        public function rules(): array
        {
            return $this->passwordRules();
        }
    };

    $rules = $subject->rules();

    expect($rules)->toHaveCount(4)
        ->and($rules[0])->toBe('required')
        ->and($rules[1])->toBe('string')
        ->and($rules[2])->toBeInstanceOf(Password::class)
        ->and($rules[3])->toBe('confirmed');
});

it('requests oauth provider tokens and throws on failure via RequestsProviderTokens trait', function (): void {
    Http::fake([
        'https://provider.test/oauth/token' => Http::sequence()
            ->push(['access_token' => 'tok_123', 'refresh_token' => 'ref_456', 'expires_in' => 3600], 200)
            ->push(['error' => 'invalid_grant'], 400),
    ]);

    $subject = new class()
    {
        use RequestsProviderTokens;

        /**
         * @param  array<string, mixed>  $cfg
         * @param  array<string, string>  $form
         * @return array<string, mixed>
         */
        public function fetch(array $cfg, array $form): array
        {
            return $this->requestProviderTokens($cfg, $form);
        }
    };

    $cfg = [
        'client_id' => 'client-abc',
        'client_secret' => 'secret-xyz',
        'token_url' => 'https://provider.test/oauth/token',
    ];

    $result = $subject->fetch($cfg, [
        'grant_type' => 'authorization_code',
        'code' => 'auth-code-1',
    ]);

    expect($result)->toBe([
        'access_token' => 'tok_123',
        'refresh_token' => 'ref_456',
        'expires_in' => 3600,
    ]);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://provider.test/oauth/token'
        && $request->isForm()
        && $request['grant_type'] === 'authorization_code'
        && $request['code'] === 'auth-code-1');

    expect(fn () => $subject->fetch($cfg, ['grant_type' => 'refresh_token', 'refresh_token' => 'bad']))
        ->toThrow(RequestException::class);
});
