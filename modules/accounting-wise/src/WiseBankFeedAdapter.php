<?php

declare(strict_types=1);

namespace Liberu\Accounting\Wise;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Liberu\Accounting\BankFeeds\Contracts\BankFeedAdapter;
use Liberu\Accounting\BankFeeds\Models\BankFeedConnection;
use Liberu\Foundation\Integrations\Contracts\OAuthIntegrationAdapter;
use Liberu\Foundation\Integrations\Contracts\PaymentProviderAdapter;
use Liberu\Payment\Contracts\Contracts\OutboundPaymentAdapterContract;
use Liberu\Payment\Contracts\DTOs\ConnectionHealthDto;
use Liberu\Payment\Contracts\DTOs\MerchantAccountContextDto;
use Liberu\Payment\Contracts\DTOs\PaymentCapabilityDescriptorDto;

final class WiseBankFeedAdapter implements BankFeedAdapter, OAuthIntegrationAdapter, OutboundPaymentAdapterContract, PaymentProviderAdapter
{
    public function __construct(private readonly ?BankFeedConnection $connection = null) {}

    public function name(): string
    {
        return 'wise';
    }

    public function providerKey(): string
    {
        return $this->name();
    }

    public function descriptor(): PaymentCapabilityDescriptorDto
    {
        return new PaymentCapabilityDescriptorDto(
            providerKey: $this->providerKey(),
            displayName: 'Wise',
            supportedMethods: ['bank_transfer'],
            supportedCurrencies: [],
            supportsAuthorizeOnly: false,
            supportsPartialCapture: false,
            supportsPartialRefund: false,
            supportsVaulting: false,
            supportsDisputes: false,
            supportsSettlementReconciliation: false,
        );
    }

    public function testConnection(MerchantAccountContextDto $account): ConnectionHealthDto
    {
        $token = $account->credentials['access_token'] ?? null;
        if (! is_string($token) || trim($token) === '') {
            return new ConnectionHealthDto($this->providerKey(), false, 'An access token is required.');
        }

        try {
            $healthy = $this->paymentClient($account)->get($this->baseUrl($account->sandbox).'/v2/profiles')->successful();
        } catch (ConnectionException) {
            $healthy = false;
        }

        return new ConnectionHealthDto($this->providerKey(), $healthy, $healthy ? 'Connected.' : 'Provider connection failed.');
    }

    public function capabilities(): array
    {
        return ['bank-feeds', 'profiles', 'balances', 'transfers', 'oauth'];
    }

    public function test(array $credentials): bool
    {
        return filled($credentials['client_id'] ?? null) && filled($credentials['client_secret'] ?? null);
    }

    public function authorizationUrl(string $state): string
    {
        return $this->authUrl().'?'.http_build_query(['client_id' => $this->clientId(), 'redirect_uri' => config('services.wise.redirect_uri'), 'response_type' => 'code', 'state' => $state, 'scope' => 'transfers balances.read']);
    }

    public function exchangeAuthorizationCode(string $code): array
    {
        return Http::timeout(15)->connectTimeout(5)->withBasicAuth($this->clientId(), (string) config('services.wise.client_secret', ''))->asForm()->post($this->baseUrl().'/oauth/v2/token', ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => config('services.wise.redirect_uri')])->throw()->json();
    }

    public function refreshAccessToken(string $refreshToken): array
    {
        return Http::timeout(15)->connectTimeout(5)->withBasicAuth($this->clientId(), (string) config('services.wise.client_secret', ''))->asForm()->post($this->baseUrl().'/oauth/v2/token', ['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken])->throw()->json();
    }

    public function getProfiles(BankFeedConnection $connection): array
    {
        return Http::timeout(15)->connectTimeout(5)->withToken($connection->access_token)->get($this->baseUrl().'/v2/profiles')->throw()->json();
    }

    public function getBalances(BankFeedConnection $connection, int $profileId, string $type = 'STANDARD'): array
    {
        return Http::timeout(15)->connectTimeout(5)->withToken($connection->access_token)->get($this->baseUrl().'/v4/profiles/'.$profileId.'/balances', ['types' => $type])->throw()->json();
    }

    public function verifyWebhookSignature(string $bodyJson, string $signature, string $publicKey): bool
    {
        if ($publicKey === '' || $signature === '') {
            return false;
        }
        $decoded = base64_decode($signature, true);
        $key = openssl_pkey_get_public($publicKey);

        return $decoded !== false && $key !== false && openssl_verify($bodyJson, $decoded, $key, OPENSSL_ALGO_SHA256) === 1;
    }

    public function fetch(BankFeedConnection $connection): array
    {
        $credentials = $connection->credentials ?? [];
        $profile = $credentials['profile_id'] ?? null;
        if ($profile === null) {
            return ['added' => [], 'modified' => [], 'removed' => [], 'next_cursor' => $connection->cursor];
        }
        $response = Http::timeout(30)->connectTimeout(10)->retry(2, 200)->withToken($connection->access_token)->get($this->baseUrl().'/v1/transfers', ['profile' => $profile, 'limit' => 100, 'status' => $credentials['status'] ?? 'outgoing_payment_sent', 'createdDateStart' => $credentials['created_date_start'] ?? null, 'createdDateEnd' => $credentials['created_date_end'] ?? null]);
        $response->throw();
        $transfers = $response->json();
        $transfers = array_is_list($transfers) ? $transfers : ($transfers['transfers'] ?? []);

        return ['added' => array_map(fn (array $transfer): array => $this->normalize($transfer), $transfers), 'modified' => [], 'removed' => [], 'next_cursor' => $connection->cursor];
    }

    public function sendPayment(array $payment): array
    {
        return $this->sendOutboundPayment($this->bankFeedAccount(), $payment);
    }

    public function sendBulkPayments(string $title, array $payments, ?string $scheduleFor = null): array
    {
        return $this->sendOutboundBatch($this->bankFeedAccount(), $title, $payments, $scheduleFor);
    }

    public function sendOutboundPayment(MerchantAccountContextDto $account, array $payment): array
    {
        $this->validateTransfer($payment);

        return $this->createTransfer($account, $payment);
    }

    public function sendOutboundBatch(MerchantAccountContextDto $account, string $title, array $payments, ?string $scheduleFor = null): array
    {
        if ($scheduleFor !== null) {
            throw new \InvalidArgumentException('Wise transfer creation does not support scheduled batches.');
        }

        if (count($payments) > 100) {
            throw new \InvalidArgumentException('Wise bulk submissions are limited to 100 transfers.');
        }

        // Validate the entire batch before submitting any transfer. The caller
        // can safely retry a partially submitted batch with the same IDs.
        foreach ($payments as $payment) {
            $this->validateTransfer($payment);
        }

        return [
            'title' => $title,
            'payments' => array_map(fn (array $payment): array => $this->createTransfer($account, $payment), $payments),
        ];
    }

    private function validateTransfer(array $payment): void
    {
        $transactionId = $payment['customerTransactionId'] ?? null;
        if (! is_string($transactionId) || trim($transactionId) === '') {
            throw new \InvalidArgumentException('A customerTransactionId is required for Wise transfers.');
        }
    }

    private function createTransfer(MerchantAccountContextDto $account, array $payment): array
    {
        return $this->paymentClient($account)->post($this->baseUrl($account->sandbox).'/v1/transfers', $payment)->throw()->json();
    }

    private function normalize(array $transfer): array
    {
        $amount = $transfer['targetValue'] ?? $transfer['sourceValue'] ?? 0;

        return ['external_id' => (string) ($transfer['id'] ?? ''), 'transaction_date' => substr((string) ($transfer['createdDate'] ?? now()->toDateString()), 0, 10), 'amount' => -abs((float) $amount), 'currency' => $transfer['targetCurrency'] ?? $transfer['sourceCurrency'] ?? 'USD', 'status' => in_array($transfer['status'] ?? '', ['outgoing_payment_sent', 'completed'], true) ? 'posted' : 'pending', 'description' => $transfer['reference'] ?? 'Wise transfer', 'raw_data' => $transfer];
    }

    private function baseUrl(?bool $sandbox = null): string
    {
        return ($sandbox ?? (config('services.wise.environment', 'sandbox') !== 'production')) ? 'https://api.sandbox.transferwise.tech' : 'https://api.transferwise.com';
    }

    private function authUrl(): string
    {
        return config('services.wise.environment', 'sandbox') === 'production' ? 'https://wise.com/oauth/v2/authorize' : 'https://sandbox.transferwise.tech/oauth/v2/authorize';
    }

    private function clientId(): string
    {
        return (string) config('services.wise.client_id', '');
    }

    private function bankFeedAccount(): MerchantAccountContextDto
    {
        if ($this->connection === null) {
            throw new \RuntimeException('A connection is required for payments.');
        }

        return new MerchantAccountContextDto(
            tenantId: (string) $this->connection->team_id,
            providerKey: $this->providerKey(),
            credentials: ['access_token' => $this->connection->access_token],
            sandbox: config('services.wise.environment', 'sandbox') !== 'production',
        );
    }

    private function paymentClient(MerchantAccountContextDto $account): PendingRequest
    {
        $token = $account->credentials['access_token'] ?? null;
        if (! is_string($token) || trim($token) === '') {
            throw new \InvalidArgumentException('An access token is required for payments.');
        }

        return Http::timeout(30)->connectTimeout(10)->withToken($token);
    }
}
