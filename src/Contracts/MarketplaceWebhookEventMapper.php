<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

/**
 * Translates a verified MERCHANT webhook into neutral domain events.
 *
 * It is a separate mapper, not extra branches on the shipped one, and the reason is a behavior change
 * nobody would have asked for. A single-merchant installation already receives `account.*` events today —
 * about its OWN platform account — and today they fall through to nothing and are ignored. Teaching the
 * shipped mapper to recognize them would make every existing installation start emitting domain events and
 * running effects it has never run, on the next deploy, with no config change and no announcement.
 *
 * Bound only where the merchant endpoint reads it, so the shipped mapper stays byte-identical.
 */
interface MarketplaceWebhookEventMapper extends WebhookEventMapper {}
