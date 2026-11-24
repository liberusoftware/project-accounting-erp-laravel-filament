<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Liberu\Accounting\BankFeeds\Models\BankFeedConnection;
use Liberu\Accounting\BankFeeds\Models\BankFeedInstitution;
use Liberu\Accounting\Plaid\PlaidBankFeedAdapter;
use Liberu\Accounting\Plaid\PlaidServiceProvider;
use Liberu\Foundation\Integrations\Support\IntegrationRegistry;

it('registers and boots the plaid service provider with integration registry', function (): void {
    $provider = $this->app->register(PlaidServiceProvider::class, force: true);
    $provider->boot();

    $adapter = $this->app->make(PlaidBankFeedAdapter::class);
    expect($adapter)->toBeInstanceOf(PlaidBankFeedAdapter::class)
        ->and($adapter->name())->toBe('plaid')
        ->and($adapter->capabilities())->toBe(['bank-feeds', 'institutions', 'balances', 'incremental-sync', 'webhooks']);

    if ($this->app->bound(IntegrationRegistry::class)) {
        $registry = $this->app->make(IntegrationRegistry::class);
        expect($registry->all())->toHaveKey('plaid');
    }
});

it('validates credentials and calls all plaid endpoints across environments', function (): void {
    $adapter = new PlaidBankFeedAdapter();

    expect($adapter->test(['client_id' => 'cid', 'secret' => 'sec']))->toBeTrue()
        ->and($adapter->test(['client_id' => 'cid']))->toBeFalse()
        ->and($adapter->test([]))->toBeFalse();

    config()->set('services.plaid.client_id', 'plaid-cid');
    config()->set('services.plaid.secret', 'plaid-sec');
    config()->set('services.plaid.environment', 'sandbox');
    config()->set('services.plaid.oauth_redirect_uri', 'https://app.test/plaid/callback');

    Http::fake([
        'https://sandbox.plaid.com/link/token/create' => Http::response(['link_token' => 'link-sandbox-1']),
        'https://development.plaid.com/item/public_token/exchange' => Http::response(['access_token' => 'access-dev-1', 'item_id' => 'item-1']),
        'https://production.plaid.com/institutions/get_by_id' => Http::response(['institution' => ['name' => 'Chase']]),
        'https://production.plaid.com/accounts/get' => Http::response(['accounts' => [['account_id' => 'acc-1']]]),
        'https://production.plaid.com/accounts/balance/get' => Http::response(['accounts' => [['account_id' => 'acc-1', 'balances' => ['current' => 100]]]]),
        'https://production.plaid.com/item/remove' => Http::response(['removed' => true]),
    ]);

    $link = $adapter->createLinkToken(42, 'en', 'access-existing');
    expect($link)->toBe(['link_token' => 'link-sandbox-1']);

    config()->set('services.plaid.environment', 'development');
    expect($adapter->exchangePublicToken('public-1'))->toBe(['access_token' => 'access-dev-1', 'item_id' => 'item-1']);

    config()->set('services.plaid.environment', 'production');
    expect($adapter->getInstitution('ins_1'))->toBe(['institution' => ['name' => 'Chase']])
        ->and($adapter->getAccounts('access-1'))->toBe(['accounts' => [['account_id' => 'acc-1']]])
        ->and($adapter->getBalances('access-1', ['acc-1']))->toBe(['accounts' => [['account_id' => 'acc-1', 'balances' => ['current' => 100]]]])
        ->and($adapter->getBalances('access-1'))->toBe(['accounts' => [['account_id' => 'acc-1', 'balances' => ['current' => 100]]]])
        ->and($adapter->removeItem('access-1'))->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://sandbox.plaid.com/link/token/create'
        && $request['redirect_uri'] === 'https://app.test/plaid/callback'
        && $request['access_token'] === 'access-existing');
});

it('fetches and normalizes transactions and throws on plaid http failure', function (): void {
    config()->set('services.plaid.client_id', 'plaid-cid');
    config()->set('services.plaid.secret', 'plaid-sec');
    config()->set('services.plaid.environment', 'sandbox');

    $institution = BankFeedInstitution::query()->create([
        'provider' => 'plaid',
        'external_id' => 'ins_test',
        'name' => 'Test Bank',
    ]);

    $connection = BankFeedConnection::query()->create([
        'team_id' => 1,
        'institution_id' => $institution->id,
        'provider' => 'plaid',
        'name' => 'Main Checking',
        'external_reference' => 'item_test',
        'access_token' => 'access-sandbox-token',
        'cursor' => 'cur-0',
    ]);

    Http::fake([
        'https://sandbox.plaid.com/transactions/sync' => Http::sequence()
            ->push([
                'added' => [
                    [
                        'transaction_id' => 'tx_debit',
                        'date' => '2026-07-10',
                        'amount' => 45.25,
                        'iso_currency_code' => 'USD',
                        'pending' => false,
                        'name' => 'Coffee Shop',
                        'category' => ['Food and Drink', 'Coffee'],
                    ],
                ],
                'modified' => [
                    [
                        'transaction_id' => 'tx_credit',
                        'authorized_date' => '2026-07-11',
                        'amount' => -200.00,
                        'pending' => true,
                        'name' => 'Refund',
                    ],
                ],
                'removed' => [
                    ['transaction_id' => 'tx_gone'],
                ],
                'next_cursor' => 'cur-1',
            ])
            ->push(['error_message' => 'Unauthorized'], 401),
    ]);

    $adapter = new PlaidBankFeedAdapter();
    $result = $adapter->fetch($connection);

    expect($result['next_cursor'])->toBe('cur-1')
        ->and($result['removed'])->toBe(['tx_gone'])
        ->and($result['added'][0])->toMatchArray([
            'external_id' => 'tx_debit',
            'transaction_date' => '2026-07-10',
            'amount' => -45.25,
            'currency' => 'USD',
            'status' => 'posted',
            'description' => 'Coffee Shop',
            'category' => 'coffee',
        ])
        ->and($result['modified'][0])->toMatchArray([
            'external_id' => 'tx_credit',
            'transaction_date' => '2026-07-11',
            'amount' => 200.0,
            'currency' => 'USD',
            'status' => 'pending',
            'description' => 'Refund',
            'category' => 'uncategorized',
        ]);

    expect(fn () => $adapter->fetch($connection))->toThrow(RequestException::class);
});
