<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Pushery\Billing\Webhooks\MarketplaceWebhookReceiver;
use Pushery\Billing\Webhooks\WebhookReceiver;

// Routes for the Billing for Laravel package. Loaded by the service provider via
// loadRoutesFrom(). The webhook route carries no middleware group (no CSRF): the
// driver's WebhookVerifier authenticates the request by signature instead.
//
// Config::string(), not a (string) cast over Config::get(): the repository is typed
// `mixed`, so a cast silently accepts whatever a consumer put in their config -- an
// array would become the literal "Array" and mount a route nobody could ever hit.
// Config::string() throws instead, naming the key, at boot.

Route::post(Config::string('billing.webhook_path', 'billing/webhook'), WebhookReceiver::class)
    ->name('billing.webhook');

// Provider events about a MERCHANT arrive on their own endpoint, signed with their own secret. It is a
// separate route because it is a separate trust boundary: one endpoint whose verifier accepted either
// secret would let the merchant key authenticate platform events, and those move the platform's own money.
// The receiver answers 404 while the marketplace is off, so a single-seller install exposes nothing.
Route::post(Config::string('billing.marketplace.webhook.path', 'billing/webhook/marketplace'), MarketplaceWebhookReceiver::class)
    ->name('billing.webhook.marketplace');
