<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use InvalidArgumentException;
use Pushery\Billing\Contracts\MerchantCouponProvisioner;
use Pushery\Billing\Discounts\DatabaseDiscountResolver;
use Pushery\Billing\Models\Coupon;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\RateLimitException;
use Stripe\StripeClient;

/**
 * Mints a Stripe coupon for a local coupon row, on the PLATFORM account.
 *
 * ## The account is not a choice here, it follows the price
 *
 * A coupon is applied to a line on a session, so it has to exist on the account that session runs on — the
 * same account the price lives on. Every session this package opens runs on the platform account, whatever
 * the posture, because both charge types it offers are platform charges. {@see StripePlatformPriceProvisioner}
 * mints the price there for the same reason. A coupon on another account is not an error at the provider; it
 * is a session that silently applies no discount.
 *
 * ## Idempotency is the ID, not a lookup
 *
 * A Stripe price can be found again by `lookup_key`; a coupon has no such field. What it does have is a
 * caller-supplied `id`, so the identity is computed from the discount itself — issuer, code, type, value,
 * currency, duration, cycles — and the coupon is retrieved under it before anything is created. Two asks for
 * the same discount therefore converge on one coupon, and a row whose value changed asks for a different id,
 * which is the invalidation the ticket asks for without a second mechanism to keep in step.
 *
 * The id is a digest rather than the readable parts. A coupon id is a bounded string and a code is free
 * text; the readable identity travels in `metadata`, where somebody debugging will look anyway.
 *
 * ## What is deliberately NOT sent
 *
 * `max_redemptions` and `redeem_by`. The local row enforces both — `CouponRedeemer` locks and counts, and
 * `isLive()` is what decides whether the code reaches this lane at all — and a second enforcement at the
 * provider would be a second set of numbers to keep in step, drifting silently the moment a host edits the
 * row. One authority for the limit, and it is the row.
 *
 * THAT LAST SENTENCE IS A CONDITION, NOT A GUARANTEE, and reading it as one has already cost a consumer
 * a broken promise to an end user. The row is the authority **while the redemption goes through
 * `CouponRedeemer`**. On a HOSTED lane it does not go through anything of ours: the discount is applied
 * inside the provider's checkout session, the consumer is not in that transaction, and nothing afterwards
 * tells them which code took effect. The single authority is then one that never speaks —
 * `redeemed_count` stays 0 and `max_redemptions` is decoration, while the provider was never given the
 * limit either. A creator who set "100 redemptions" has nobody holding it.
 *
 * So a hosted consumer reads the redeemed code off `SubscriptionStateChanged::$couponCode` and books the
 * redemption themselves. That field exists for exactly this, and it carries the LOCAL code rather than the
 * provider id, so booking it needs none of the provider arithmetic this class is here to encapsulate.
 */
final readonly class StripePlatformCouponProvisioner implements MerchantCouponProvisioner
{
    public function __construct(private StripeClient $stripe) {}

    public function provision(Coupon $coupon): string
    {
        $payload = $this->discountOf($coupon);
        $id = $this->couponId($coupon);

        // The platform account, so no `stripe_account` option — the absence is the behavior, which is why
        // the arm asserts on the recorded request rather than on this line.
        try {
            return (string) $this->stripe->coupons->retrieve($id)->id;
        } catch (RateLimitException $e) {
            // A 429 is TRANSIENT and only lands here because the SDK makes RateLimitException a subclass of
            // InvalidRequestException. Swallowing it would file "try again" as "does not exist" and mint a
            // second coupon under an id that is already taken.
            throw $e;
        } catch (InvalidRequestException) {
            // Not there yet. Every other reading of this exception — a malformed id, a revoked key — would
            // fail again on the create below, with the provider's own message rather than a guess.
        }

        $key = $coupon->getKey();

        $request = [
            'id' => $id,
            'duration' => (string) $coupon->duration,
            'name' => $coupon->code,
            'metadata' => [
                'billing_coupon_code' => $coupon->code,
                'billing_coupon_issuer' => (string) $coupon->merchant_uid,
                'billing_coupon_id' => is_scalar($key) ? (string) $key : '',
            ],
        ];

        if (isset($payload['percent_off'])) {
            $request['percent_off'] = $payload['percent_off'];
        }

        if (isset($payload['amount_off'], $payload['currency'])) {
            $request['amount_off'] = $payload['amount_off'];
            $request['currency'] = $payload['currency'];
        }

        // Only where the duration uses it. Sending a cycle count beside `forever` is a field the provider
        // rejects, and beside `once` it is a number that means nothing.
        if ($coupon->duration === 'repeating') {
            $request['duration_in_months'] = max(1, $coupon->duration_in_cycles ?? 1);
        }

        return (string) $this->stripe->coupons->create($request)->id;
    }

    /**
     * The discount itself, in the provider's own vocabulary.
     *
     * A row that describes neither a usable percentage nor a fixed amount with a currency is refused rather
     * than sent: the provider would reject it anyway, and refusing here names the row instead of returning
     * the provider's message about a payload the caller never wrote. The bounds are the ones
     * {@see DatabaseDiscountResolver} already applies, so a row this package
     * would not resolve locally cannot be minted remotely either.
     *
     * @return array{percent_off?: int, amount_off?: int, currency?: string}
     */
    private function discountOf(Coupon $coupon): array
    {
        if ($coupon->type === 'percent' && $coupon->value >= 1 && $coupon->value <= 100) {
            return ['percent_off' => $coupon->value];
        }

        if ($coupon->type === 'fixed' && is_string($coupon->currency) && $coupon->value > 0) {
            return ['amount_off' => $coupon->value, 'currency' => strtolower($coupon->currency)];
        }

        throw new InvalidArgumentException(
            "Coupon '{$coupon->code}' describes no discount a provider can carry: a percent coupon needs a "
            .'value between 1 and 100, a fixed one a positive value and a currency. The row is the same one '
            .'the local resolver would refuse, so minting it would make the two disagree about one code.'
        );
    }

    /**
     * The coupon's identity at the provider, from everything about it that cannot later change.
     *
     * `duration_in_cycles` is folded in only where the duration uses it: a `forever` row that happens to
     * carry a stale cycle count is the same coupon as one that does not, and keying on the column would mint
     * a second coupon over a value the provider never sees.
     */
    private function couponId(Coupon $coupon): string
    {
        $parts = [
            (string) $coupon->merchant_uid,
            $coupon->code,
            (string) $coupon->type,
            (string) $coupon->value,
            strtolower((string) $coupon->currency),
            (string) $coupon->duration,
            $coupon->duration === 'repeating' ? (string) max(1, $coupon->duration_in_cycles ?? 1) : '',
        ];

        return 'blc_'.substr(hash('sha256', implode('|', $parts)), 0, 24);
    }
}
