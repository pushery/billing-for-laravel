<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks\Effects;

use Pushery\Billing\Events\PaymentFailed;
use Pushery\Billing\Support\BillingManager;
use Pushery\Billing\Support\LocalBillingEngine;

/**
 * Turns a held cycle into a real failure when its in-flight charge comes back refused.
 *
 * The other direction of {@see SettleCycleOnPayment}, and the reason holding a cycle open is not simply
 * optimism. A bank debit can be accepted and bounce days later; THAT is the moment dunning belongs to.
 * Before this, dunning fired when the payment was CREATED — which is to say, on every such subscriber,
 * every cycle, whether or not anything was ever wrong.
 *
 * Without it a bounced debit would leave the cycle held forever: no charge, no ladder, no notice. The
 * failure path has to be as real as the success one, or holding is just a different way of losing the row.
 */
final readonly class FailCycleOnPayment
{
    public function __construct(private BillingManager $manager) {}

    public function __invoke(PaymentFailed $event): void
    {
        // Resolved through the MANAGER, which is what everything else in this package does and what the
        // container can actually build. `BillingEngine` is an interface bound NOWHERE — the engine is
        // reached only as `driver()->engine()` — so injecting it would make this class unconstructible, and
        // `HandleWebhookEffect` builds effects with `Container::make()`. Every held cycle would then stay
        // held forever while every test stayed green, because a test that constructs the engine by hand
        // never asks the container the question a real delivery asks.
        $engine = $this->manager->driver()->engine();

        if (! $engine instanceof LocalBillingEngine) {
            return;
        }

        $engine->fail($event->reference);
    }
}
