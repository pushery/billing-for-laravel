<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Mollie;

use Pushery\Billing\Contracts\BillingDriver;
use Pushery\Billing\Contracts\BillingEngine;
use Pushery\Billing\Contracts\PaymentRails;
use Pushery\Billing\Contracts\RoutesMoney;
use Pushery\Billing\ValueObjects\DriverCapabilities;

/**
 * The Mollie driver — the package's first local-engine driver, and that is the whole difference.
 *
 * Stripe is told when to charge and charges itself, so its engine is deliberately a no-op and its
 * capabilities report rich native support. Mollie runs no billing cycle at all: it takes payments when
 * asked and nothing more. So every capability is false and the package fills each gap with its own
 * machinery — the cycle, the proration, the tax, the credit, the dunning ladder.
 *
 * That inverts what a missing piece costs. Under Stripe, a gap in the package means the provider handles
 * it. Under Mollie, a gap means nobody handles it: a subscriber is simply never billed, and nothing fails
 * to say so. It is the reason the engine and the rails are injected rather than constructed here — both
 * are exercised on their own long before anything resolves this class.
 *
 * It deliberately does not implement {@see RoutesMoney}. The marketplace runs on the Stripe driver only,
 * so no rails are built onto Mollie Connect, and claiming the interface would turn "this driver cannot
 * route" — a clear refusal at the seam — into a fatal somewhere further in.
 */
final readonly class MollieDriver implements BillingDriver
{
    public function __construct(
        private PaymentRails $rails,
        private BillingEngine $engine,
    ) {}

    public function name(): string
    {
        return 'mollie';
    }

    public function rails(): PaymentRails
    {
        return $this->rails;
    }

    public function engine(): BillingEngine
    {
        return $this->engine;
    }

    public function capabilities(): DriverCapabilities
    {
        return MollieCapabilities::make();
    }
}
