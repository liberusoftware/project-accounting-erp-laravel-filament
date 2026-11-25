<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Liberu\Foundation\Webhooks\Actions\DeliverWebhook;
use Liberu\Foundation\Webhooks\Actions\QueueWebhook;
use Liberu\Foundation\Webhooks\Actions\RegisterEndpoint;
use Liberu\Foundation\Webhooks\Actions\RotateEndpointSecret;
use Liberu\Foundation\Webhooks\Models\WebhookDelivery;
use Liberu\Foundation\Webhooks\Models\WebhookEndpoint;
use Liberu\Foundation\Webhooks\Support\RetrySchedule;
use Liberu\Foundation\Webhooks\Support\SigningSecretVault;
use Liberu\Foundation\Webhooks\Support\WebhookSigner;
use Liberu\Foundation\Webhooks\Support\WebhookUrlPolicy;
use Liberu\Foundation\Webhooks\WebhooksServiceProvider;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

it('covers WebhooksServiceProvider, RegisterEndpoint, RotateEndpointSecret, QueueWebhook, and DeliverWebhook lifecycle', function (): void {
    $provider = $this->app->register(WebhooksServiceProvider::class, force: true);
    $provider->boot();

    $vault = new SigningSecretVault();
    $signer = new WebhookSigner();
    $schedule = new RetrySchedule();
    $urlPolicy = new WebhookUrlPolicy();

    $register = new RegisterEndpoint($vault);

    // Validation failures on RegisterEndpoint
    expect(fn () => $register->handle('team:1', 'ftp://example.com/hook', ['invoice.paid']))
        ->toThrow(InvalidArgumentException::class, 'The webhook URL is invalid.');
    expect(fn () => $register->handle('team:1', 'https://93.184.216.34/hook', ['', '']))
        ->toThrow(InvalidArgumentException::class, 'At least one webhook event is required.');

    // Register active specific endpoint, wildcard endpoint, and inactive endpoint
    $specific = $register->handle('team:1', 'https://93.184.216.34/hook', ['invoice.paid', 'invoice.paid'], 'initial-secret');
    $wildcard = $register->handle('team:1', 'https://93.184.216.34/wildcard', ['*']);
    $inactive = $register->handle('team:1', 'https://93.184.216.34/inactive', ['invoice.paid']);
    $inactive->forceFill(['active' => false])->save();

    expect($specific)->toBeInstanceOf(WebhookEndpoint::class)
        ->and($specific->events)->toBe(['invoice.paid'])
        ->and($specific->active)->toBeTrue()
        ->and($vault->open($specific->signing_secret))->toBe('initial-secret');

    // Rotate secret
    $rotatedSecret = (new RotateEndpointSecret($vault))->handle($specific);
    $specific->refresh();
    expect($rotatedSecret)->not->toBe('initial-secret')
        ->and($specific->rotated_at)->not->toBeNull()
        ->and($vault->open($specific->signing_secret))->toBe($rotatedSecret);

    // QueueWebhook queues for active matching + wildcard endpoints idempotently
    $queue = new QueueWebhook();
    expect($queue->handle('evt-001', 'invoice.paid', ['amount' => 100]))->toBe(2)
        ->and($queue->handle('evt-001', 'invoice.paid', ['amount' => 100]))->toBe(0);

    $deliveries = WebhookDelivery::query()->where('event_id', 'evt-001')->get();
    expect($deliveries)->toHaveCount(2);

    $firstDelivery = $deliveries->firstWhere('endpoint_id', $specific->getKey());
    expect($firstDelivery)->toBeInstanceOf(WebhookDelivery::class)
        ->and($firstDelivery->endpoint->is($specific))->toBeTrue();

    // DeliverWebhook: 200 success, 500 failure, and exception path
    Http::fake([
        'https://93.184.216.34/hook' => Http::sequence()
            ->push('accepted', 200)
            ->push('server error', 500),
        'https://93.184.216.34/wildcard' => static function (): never {
            throw new RuntimeException('Connection reset by peer');
        },
    ]);

    $deliver = new DeliverWebhook($vault, $signer, $schedule, $urlPolicy);

    $delivered = $deliver->handle($firstDelivery);
    expect($delivered->status)->toBe('delivered')
        ->and($delivered->response_status)->toBe(200)
        ->and($delivered->next_attempt_at)->toBeNull();

    $failedHttp = $deliver->handle($delivered);
    expect($failedHttp->status)->toBe('failed')
        ->and($failedHttp->response_status)->toBe(500)
        ->and($failedHttp->next_attempt_at)->not->toBeNull();

    $wildcardDelivery = $deliveries->firstWhere('endpoint_id', $wildcard->getKey());
    $failedEx = $deliver->handle($wildcardDelivery);
    expect($failedEx->status)->toBe('failed')
        ->and($failedEx->response_excerpt)->toContain('Connection reset by peer')
        ->and($failedEx->next_attempt_at)->not->toBeNull();

    // Missing endpoint triggers 404
    $orphan = new WebhookDelivery([
        'id' => 'orphan-1',
        'endpoint_id' => 999999,
        'event_id' => 'evt-orphan',
        'event' => 'invoice.paid',
        'payload' => [],
        'status' => 'pending',
        'attempts' => 0,
    ]);
    expect(fn () => $deliver->handle($orphan))->toThrow(NotFoundHttpException::class);
});

it('covers WebhookUrlPolicy validation, DNS pinning, IPv4/IPv6 handling, and private IP rejection', function (): void {
    $policy = new WebhookUrlPolicy();

    expect(fn () => $policy->options('not-a-url'))
        ->toThrow(InvalidArgumentException::class, 'The webhook URL is invalid.');
    expect(fn () => $policy->options('https://user:pass@93.184.216.34/hook'))
        ->toThrow(InvalidArgumentException::class, 'The webhook URL is invalid.');
    expect(fn () => $policy->options('https://127.0.0.1/hook'))
        ->toThrow(InvalidArgumentException::class, 'Webhook destinations must use public IP addresses.');
    expect(fn () => $policy->options('https://[::ffff:127.0.0.1]/hook'))
        ->toThrow(InvalidArgumentException::class, 'Webhook destinations must use public IP addresses.');

    $literalV4 = $policy->options('https://93.184.216.34/hook');
    expect($literalV4['allow_redirects'])->toBeFalse()
        ->and($literalV4['curl'])->toBe([]);

    // Hostname with mocked public IPv4 + IPv6 addresses
    $stubPolicy = new class extends WebhookUrlPolicy
    {
        public array $mocked = [];

        protected function addresses(string $host): array
        {
            return $this->mocked;
        }
    };

    $stubPolicy->mocked = [];
    expect(fn () => $stubPolicy->options('https://hooks.example.com/events'))
        ->toThrow(InvalidArgumentException::class, 'The webhook host could not be resolved.');

    $stubPolicy->mocked = ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946'];
    $pinned = $stubPolicy->options('https://hooks.example.com/events');
    expect($pinned['curl'][CURLOPT_RESOLVE][0])
        ->toBe('hooks.example.com:443:93.184.216.34,[2606:2800:220:1:248:1893:25c8:1946]');

    // Exercise protected addresses() method directly on local-only hostname
    $resolvedLocal = Closure::bind(
        fn (string $host): array => $this->addresses($host),
        $policy,
        WebhookUrlPolicy::class
    )('localhost');
    expect($resolvedLocal)->toBeArray();
});
