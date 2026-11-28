<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Liberu\Payment\Adyen\AdyenPaymentServiceProvider;
use Liberu\Payment\GoCardless\GoCardlessPaymentServiceProvider;
use Liberu\Payment\Mollie\MolliePaymentServiceProvider;
use Liberu\Payment\Paddle\PaddlePaymentServiceProvider;
use Liberu\Payment\PayPal\PayPalPaymentServiceProvider;
use Liberu\Payment\Square\SquarePaymentServiceProvider;
use Liberu\Payment\Stripe\StripePaymentServiceProvider;

class ModulePackageTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            AdyenPaymentServiceProvider::class,
            GoCardlessPaymentServiceProvider::class,
            MolliePaymentServiceProvider::class,
            PaddlePaymentServiceProvider::class,
            PayPalPaymentServiceProvider::class,
            SquarePaymentServiceProvider::class,
            StripePaymentServiceProvider::class,
        ] as $provider) {
            if (class_exists($provider)) {
                $this->app->register($provider);
            }
        }
    }
}
