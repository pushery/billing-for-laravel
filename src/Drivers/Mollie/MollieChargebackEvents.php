<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Mollie;

use Mollie\Api\Resources\Chargeback;
use Pushery\Billing\Events\ChargebackReceived;
use Throwable;

/**
 * Turns Mollie's chargeback collection into neutral events, one per chargeback.
 *
 * Its own class rather than a private method on the mapper, and the reason is testability of the guards
 * rather than tidiness. The SDK's collections extend `ArrayObject` and do not guarantee their element
 * type, so what they hold is an untyped boundary — but the SDK's own hydration always produces a
 * `Chargeback` whatever the payload said, which makes the narrowing impossible to exercise through it. A
 * guard no run can enter is indistinguishable from a guard that is wrong, so the conversion is exposed
 * where a caller can hand it the shapes the type system admits.
 *
 * Two things it will not do, and both are about money:
 *
 * **It never reports the payment's amount.** A chargeback is not necessarily the whole payment — partial
 * ones exist — so the amount comes from the chargeback itself or the entry is skipped.
 *
 * **One malformed entry does not take the others with it.** The isolation is per item, because a single
 * unreadable row aborting the loop would lose every other chargeback beside it and, upstream, the
 * payment's own success — which came from an entirely different call.
 */
final readonly class MollieChargebackEvents
{
    /**
     * @param  iterable<mixed>  $chargebacks
     * @return iterable<ChargebackReceived>
     */
    public static function from(iterable $chargebacks, string $customerReference): iterable
    {
        foreach ($chargebacks as $chargeback) {
            if (! $chargeback instanceof Chargeback) {
                continue;
            }

            try {
                $amount = MollieAmount::fromResource($chargeback->amount);
            } catch (Throwable) {
                continue;
            }

            yield new ChargebackReceived($customerReference, (string) $chargeback->id, $amount);
        }
    }
}
