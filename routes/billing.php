<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Pushery\Billing\Webhooks\WebhookReceiver;

// Routes for the Billing for Laravel package. Loaded by the service provider via
// loadRoutesFrom(). The webhook route carries no middleware group (no CSRF): the
// driver's WebhookVerifier authenticates the request by signature instead.

Route::post((string) config('billing.webhook_path', 'billing/webhook'), WebhookReceiver::class)
    ->name('billing.webhook');
