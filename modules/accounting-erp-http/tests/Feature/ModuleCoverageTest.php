<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Liberu\Accounting\Erp\Models\Customer;
use Liberu\Accounting\Erp\Models\PremiumCatalog;
use Liberu\Accounting\Erp\Models\User;
use Liberu\Accounting\Erp\Models\Vendor;
use Liberu\Accounting\Erp\Notifications\PortalAccessNotification;
use Liberu\Accounting\Erp\Services\TeamManagementService;
use Liberu\Accounting\ErpHttp\ErpHttpServiceProvider;
use Liberu\Accounting\ErpHttp\Http\Controllers\Api\OpenApiController;
use Liberu\Accounting\ErpHttp\Http\Controllers\Controller;
use Liberu\Accounting\ErpHttp\Http\Controllers\PortalAccessController;
use Liberu\Accounting\ErpHttp\Http\Controllers\SaasPremiumBillingController;

it('boots erp http service provider and verifies base controller', function (): void {
    $provider = $this->app->register(ErpHttpServiceProvider::class, force: true);
    $provider->boot();

    expect($provider)->toBeInstanceOf(ErpHttpServiceProvider::class)
        ->and(new Controller)->toBeInstanceOf(Controller::class)
        ->and(app(OpenApiController::class))->toBeInstanceOf(Controller::class)
        ->and(app(PortalAccessController::class))->toBeInstanceOf(Controller::class)
        ->and(app(SaasPremiumBillingController::class))->toBeInstanceOf(Controller::class);
});

it('generates openapi spec from versioned api routes with and without ability scopes', function (): void {
    Route::middleware(['api', 'ability:test:read'])->get('api/v1/erp-http-scoped', fn () => response()->json(['ok' => true]));
    Route::middleware(['api'])->post('api/v1/erp-http-public', fn () => response()->json(['ok' => true]));

    $response = $this->getJson('/api/v1/openapi.json');

    $response->assertOk()
        ->assertJsonPath('openapi', '3.0.3')
        ->assertJsonPath('info.title', 'Liberu Accounting API')
        ->assertJsonPath('servers.0.url', '/api/v1')
        ->assertJsonPath('paths./erp-http-scoped.get.security.0.sanctum.0', 'test:read')
        ->assertJsonPath('paths./erp-http-public.post.security', []);

    expect($response->json('paths'))->not->toHaveKey('/openapi.json');
});

it('covers customer and vendor portal set-password, forgot-password, and invalid guard handling', function (): void {
    Notification::fake();

    $customer = Customer::factory()->create();
    $vendor = Vendor::factory()->create();

    // Customer forgot-password view + send (known + unknown)
    $this->get(route('portal.customer.forgot'))->assertOk();
    $this->post(route('portal.customer.forgot.send'), ['email' => $customer->customer_email])
        ->assertRedirect()
        ->assertSessionHas('status');
    $this->post(route('portal.customer.forgot.send'), ['email' => 'missing-customer@example.test'])
        ->assertRedirect()
        ->assertSessionHas('status');
    Notification::assertSentTo($customer, PortalAccessNotification::class);

    // Vendor forgot-password view + send
    $this->get(route('portal.vendor.forgot'))->assertOk();
    $this->post(route('portal.vendor.forgot.send'), ['email' => $vendor->email])
        ->assertRedirect()
        ->assertSessionHas('status');
    Notification::assertSentTo($vendor, PortalAccessNotification::class);

    // Customer set-password flow + single-use enforcement
    $customerUrl = URL::temporarySignedRoute('portal.customer.set-password', now()->addHour(), [
        'id' => $customer->getKey(),
        'hash' => sha1((string) $customer->password),
    ]);
    $this->get($customerUrl)->assertOk();
    $this->post($customerUrl, [
        'password' => 'Str0ng-Customer-Pw!',
        'password_confirmation' => 'Str0ng-Customer-Pw!',
    ])->assertRedirect()->assertSessionHas('status');

    expect(Hash::check('Str0ng-Customer-Pw!', (string) $customer->fresh()->password))->toBeTrue();
    $this->get($customerUrl)->assertForbidden();

    // Vendor set-password flow
    $vendorUrl = URL::temporarySignedRoute('portal.vendor.set-password', now()->addHour(), [
        'id' => $vendor->getKey(),
        'hash' => sha1((string) $vendor->password),
    ]);
    $this->get($vendorUrl)->assertOk();
    $this->post($vendorUrl, [
        'password' => 'Str0ng-Vendor-Pw!',
        'password_confirmation' => 'Str0ng-Vendor-Pw!',
    ])->assertRedirect()->assertSessionHas('status');

    expect(Hash::check('Str0ng-Vendor-Pw!', (string) $vendor->fresh()->password))->toBeTrue();

    // Invalid guard aborts with 404
    Route::get('/_test/invalid-portal-guard', [PortalAccessController::class, 'showForgot'])
        ->defaults('guard', 'unknown');
    $this->get('/_test/invalid-portal-guard')->assertNotFound();
});

it('covers saas premium billing show, checkout, portal, success, webhook, and access guards', function (): void {
    $this->withoutVite();

    $user = User::factory()->create();
    $team = app(TeamManagementService::class)->createPersonalTeamForUser($user);
    $user = $user->fresh();

    // Disabled premium returns 404 on show and checkout (team guard)
    config()->set('premium.enabled', false);
    $this->actingAs($user)->get(route('billing.premium'))->assertNotFound();
    $this->actingAs($user)->post(route('billing.premium.checkout'), ['interval' => 'month'])->assertNotFound();

    // Enabled premium but user has no team returns 403
    config()->set('premium.enabled', true);
    $homelessUser = User::factory()->create(['current_team_id' => null]);
    $this->actingAs($homelessUser)->get(route('billing.premium'))->assertForbidden();

    // Show page succeeds when enabled and user belongs to team
    $this->actingAs($user)->get(route('billing.premium'))->assertOk();

    // Success redirect
    $this->actingAs($user)->get(route('billing.premium.success'))
        ->assertRedirect(route('billing.premium'))
        ->assertSessionHas('status');

    // Checkout and portal redirects
    config()->set('premium.secret', 'sk_test_erp_http');
    PremiumCatalog::query()->create([
        'catalog_key' => 'premium',
        'stripe_product_id' => 'prod_http_test',
        'stripe_monthly_price_id' => 'price_http_monthly',
        'stripe_yearly_price_id' => 'price_http_yearly',
    ]);
    $team->forceFill(['stripe_customer_id' => 'cus_http_test'])->save();

    Http::fake([
        'https://api.stripe.com/v1/checkout/sessions' => Http::response(['url' => 'https://checkout.stripe.test/http-session']),
        'https://api.stripe.com/v1/billing_portal/sessions' => Http::response(['url' => 'https://billing.stripe.test/http-portal']),
    ]);

    $this->actingAs($user)
        ->post(route('billing.premium.checkout'), ['interval' => 'month'])
        ->assertRedirect('https://checkout.stripe.test/http-session');

    $this->actingAs($user)
        ->post(route('billing.premium.portal'))
        ->assertRedirect('https://billing.stripe.test/http-portal');

    // Stripe webhook returns 204 No Content
    config()->set('premium.webhook_secret', 'whsec_http_test');
    $event = [
        'id' => 'evt_http_1',
        'type' => 'invoice.payment_failed',
        'data' => [
            'object' => [
                'customer' => 'cus_http_test',
                'metadata' => ['team_id' => (string) $team->getKey()],
            ],
        ],
    ];
    $payload = json_encode($event, JSON_THROW_ON_ERROR);
    $timestamp = (string) time();
    $sig = hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_http_test');

    $this->postJson(route('stripe.webhook'), $event, [
        'Stripe-Signature' => "t={$timestamp},v1={$sig}",
    ])->assertNoContent();

    expect($team->fresh()->premium_status)->toBe('past_due');
});
