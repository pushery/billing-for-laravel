<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Mollie\Sandbox;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Config;
use Pushery\Billing\Support\SafeExternalUrl;

/**
 * The checkout of a payment while the sandbox answers for Mollie, in place of Mollie's hosted page: it completes the
 * payment with the configured outcome, pings it to the webhook route in this request, and sends the customer to the
 * payment's `redirectUrl`, the way Mollie sends them back.
 *
 * The address is signed, so only the link the sandbox handed out completes a payment, and it answers 404 while billing
 * or the sandbox is off. A payment that was completed before is not completed again: the customer is only sent back.
 */
final readonly class SandboxCheckout
{
    public function __invoke(string $payment): Response|RedirectResponse
    {
        if (! Config::boolean('billing.enabled', true) || ! SandboxMollie::enabled()) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $completed = SandboxMollie::fromConfig()->complete($payment);

        if ($completed === null) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $back = SafeExternalUrl::orNull($completed['redirectUrl'] ?? null);

        return $back === null ? new Response('', Response::HTTP_NO_CONTENT) : new RedirectResponse($back);
    }
}
