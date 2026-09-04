<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Contracts\PaymentRails;
use Pushery\Billing\Contracts\SupplyRegimeResolver;
use Pushery\Billing\Enums\ChargeType;
use Pushery\Billing\Enums\FeeRefundPolicy;
use Pushery\Billing\Exceptions\FeeRefundPolicyNotPermitted;
use Pushery\Billing\Exceptions\MarketplaceUnsupported;
use Pushery\Billing\ValueObjects\ChargeNarrative;
use Pushery\Billing\ValueObjects\ChargeResult;
use Pushery\Billing\ValueObjects\ChargeRouting;
use Pushery\Billing\ValueObjects\MandateReference;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\RefundResult;
use Pushery\Billing\ValueObjects\TokenizedMethod;
use Stripe\Charge;
use Stripe\Exception\CardException;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Stripe\StripeClient;
use Stripe\TransferReversal;

/**
 * The Stripe implementation of the lower billing layer — it moves money and stores mandates through
 * Stripe's PaymentIntent / PaymentMethod / Refund APIs, and returns only neutral value objects so the
 * engine above never touches a Stripe response.
 *
 * A card decline is a business outcome, not an error: it comes back as a failed {@see ChargeResult}.
 * Every other Stripe error (bad request, missing customer, connection failure) propagates, because it
 * signals a misconfiguration the caller must not silently treat as "the customer's card was declined".
 *
 * A charge has more than two outcomes: besides settled and declined, it may need the cardholder to
 * authenticate (3-D Secure) or be a bank debit still processing (SEPA). Those are carried out as
 * requires-action / pending on the ChargeResult, never collapsed onto "declined".
 */
final readonly class StripePaymentRails implements PaymentRails
{
    /**
     * A PaymentIntent's `description` carries 1000 characters — four times Mollie's field.
     *
     * The two limits stay separate rather than collapsing onto the smaller one: a shared limit would trim
     * every Stripe description to Mollie's length for no reason other than that Mollie also exists.
     */
    private const int DESCRIPTION_LIMIT = 1000;

    public function __construct(
        private StripeClient $stripe,
        private Repository $config,
        private SupplyRegimeResolver $regimes,
    ) {}

    public function charge(Money $amount, string $token, ?string $idempotencyKey = null, ?ChargeRouting $routing = null, ?ChargeNarrative $narrative = null): ChargeResult
    {
        $params = $this->routed([
            'amount' => $amount->minorUnits,
            'currency' => strtolower($amount->currency),
            'payment_method' => $token,
            'confirm' => true,
            'off_session' => false,
        ], $amount, $routing);

        return $this->settle(
            // The SDK's generated param shape cannot express a payload assembled at runtime: the routing keys
            // are present or absent depending on the charge type, and an unrouted payment must carry neither.
            // The payload IS a valid PaymentIntent request; its exact fields are asserted one by one in
            // StripeMarketplaceRoutingTest, including the assertion that an unrouted charge emits none of them.
            // @phpstan-ignore argument.type
            fn (): PaymentIntent => $this->stripe->paymentIntents->create($this->traced($params, $idempotencyKey, $narrative), $this->options($idempotencyKey)),
            $amount,
            $routing,
        );
    }

    public function createMandate(string $customerReference, string $token): MandateReference
    {
        $method = $this->stripe->paymentMethods->attach($token, ['customer' => $customerReference]);

        return new MandateReference(
            id: $method->id,
            method: $method->type,
            reusable: $this->isReusable($method->type),
            customerReference: $customerReference,
        );
    }

    public function tokenize(string $paymentData): TokenizedMethod
    {
        $method = $this->stripe->paymentMethods->retrieve($paymentData);

        // A non-card method carries no `card` hash. The `??` uses Stripe's
        // notice-free __isset, so reading a card-less method neither warns nor
        // fabricates a brand/last4.
        $card = $method->card ?? null;

        return new TokenizedMethod(
            token: $method->id,
            offSessionCapable: $this->isReusable($method->type),
            brand: $card?->brand,
            last4: $card?->last4,
        );
    }

    /**
     * Charge a stored mandate off-session (merchant-initiated). The mandate id is the Stripe
     * payment-method reference {@see createMandate()} attached to the customer; Stripe resolves the
     * customer from the attached method, and `off_session: true` flags the absent-cardholder intent so
     * the correct SCA exemption is requested.
     */
    public function offSessionCharge(Money $amount, MandateReference $mandate, ?string $idempotencyKey = null, ?ChargeRouting $routing = null, ?ChargeNarrative $narrative = null): ChargeResult
    {
        $customer = $mandate->customerReference;
        $options = $this->options($idempotencyKey);

        // Stripe needs the customer to charge a stored payment method off-session; a mandate created
        // by createMandate() carries it. Without it, fall back to a payment-method-only intent.
        $params = $this->routed(
            $customer !== null
                ? [
                    'amount' => $amount->minorUnits,
                    'currency' => strtolower($amount->currency),
                    'payment_method' => $mandate->id,
                    'confirm' => true,
                    'off_session' => true,
                    'customer' => $customer,
                ]
                : [
                    'amount' => $amount->minorUnits,
                    'currency' => strtolower($amount->currency),
                    'payment_method' => $mandate->id,
                    'confirm' => true,
                    'off_session' => true,
                ],
            $amount,
            $routing,
        );

        return $this->settle(
            // The SDK's generated param shape cannot express a payload assembled at runtime: the routing keys
            // are present or absent depending on the charge type, and an unrouted payment must carry neither.
            // The payload IS a valid PaymentIntent request; its exact fields are asserted one by one in
            // StripeMarketplaceRoutingTest, including the assertion that an unrouted charge emits none of them.
            // @phpstan-ignore argument.type
            fn (): PaymentIntent => $this->stripe->paymentIntents->create($this->traced($params, $idempotencyKey, $narrative), $options),
            $amount,
            $routing,
        );
    }

    public function refund(string $chargeReference, Money $amount, ?string $idempotencyKey = null, ?ChargeRouting $routing = null): RefundResult
    {
        $params = ['payment_intent' => $chargeReference, 'amount' => $amount->minorUnits];

        // The flag only means something on a DESTINATION charge, where the transfer is part of the payment
        // and the provider can unwind both together. On a separate transfer the money moved in its own call,
        // and refunding the payment does not touch it — the flag is accepted and does nothing.
        //
        // Setting it anyway would be worse than omitting it. A no-op flag reads as "the reversal is handled"
        // to everyone who looks, while the merchant keeps their share and the platform refunds the buyer out
        // of its own money. The gap has to be VISIBLE, so the lane that cannot reverse here says nothing
        // rather than something untrue — and `reversedTransferReference` comes back null, which is the
        // honest answer a caller can act on.
        //
        // Note also what the flag does when it DOES apply: it reverses proportionally. That is the number
        // ClawbackCalculator exists to disprove — with any fixed fee component, a partial refund owes more
        // than the proportional share, forever, and both figures look reasonable. Reversing explicitly with
        // the calculated amount is the separate-transfer lane's job.
        if ($routing instanceof ChargeRouting && $routing->type === ChargeType::Destination) {
            $params['reverse_transfer'] = true;

            // The VALUE here follows the configured policy, and it did not use to. It was the constant
            // `false` — meaning "the platform keeps its fee" — while the shipped default of
            // `billing.marketplace.fee.refund_policy` says `refund`, and a go-live checkpoint reads that key
            // and REFUSES `retain` under a commission chain. So the package validated a setting nothing
            // obeyed, and then did the opposite of it on every refund.
            //
            // The reason the checkpoint refuses is the reason this has to follow it: keeping a fee
            // presupposes a document the platform issued the merchant for a service. A commission chain has
            // none — the platform buys and resells, and unwinding the sale unwinds both supplies. Money kept
            // afterwards sits on no supply at all, which is turnover on a tax return with nothing behind it.
            $params['refund_application_fee'] = $this->feeRefundPolicy()->refundsPlatformFee();

            // Without this the provider answers with the reversal's ID and nothing else, so the AMOUNT that
            // came back is unknowable from the response — and a caller that needs it would have to either
            // make a second call or infer it from the refund total. The inference is the trap: it is right
            // only while the fee is a pure percentage, and silently short on any fee with a fixed part.
            //
            // Expanding costs no extra round trip, and it is asked for only on the lane that can actually
            // reverse. An unrouted refund's payload stays byte-for-byte what it has always been.
            $params['expand'] = ['transfer_reversal'];
        }

        $refund = $this->stripe->refunds->create($params, $this->options($idempotencyKey));

        return new RefundResult(
            successful: $refund->status === 'succeeded' || $refund->status === 'pending',
            reference: $refund->id,
            amount: $amount,
            // The provider's own reference for the reversal, never a flag set from our intent — a refund
            // reporting a reversal that did not happen is the failure this whole path exists to prevent.
            reversedTransferReference: $this->transferReversalOf($refund),
            // Read off the reversal the provider MADE, not off the refund we asked for. The two differ on
            // every partial reversal, and the difference is money owed in one direction or the other.
            transferReversed: $this->transferReversedIn($refund),
            // Deliberately absent here: see the field's own documentation. This lane can only learn the fee
            // refund as a cumulative total on the ApplicationFee, which is not this refund's figure once a
            // second partial refund exists — and a number that is right until it quietly is not is worse
            // than none at all.
        );
    }

    /**
     * Add the routing fields to a PaymentIntent, or return it untouched.
     *
     * Untouched is load-bearing. An unrouted payment must reach the provider with exactly the fields it
     * has always reached it with — not the same fields plus nulls, which would still be a change in what
     * the provider is told and could still move behavior under a single seller who asked for nothing.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function routed(array $params, Money $amount, ?ChargeRouting $routing): array
    {
        if (! $routing instanceof ChargeRouting) {
            return $params;
        }

        $routing->assertFitsWithin($amount);

        if ($routing->type === ChargeType::Destination) {
            $params['transfer_data'] = ['destination' => $routing->destination->accountId];
            $params['application_fee_amount'] = $routing->applicationFee->minorUnits;
        }

        // A separate transfer moves the merchant's share after the payment settles, so the intent itself
        // correctly carries no destination — the platform is the merchant of record and takes the whole
        // amount. That much is right. What these rails cannot do is the SECOND half: the later call that
        // actually moves the share.
        //
        // That call now EXISTS — `MovesMerchantShare`, implemented by `StripeMerchantTransfers`, which binds
        // the transfer to the funding charge via `source_transaction`. This comment used to say it did not,
        // which stopped being true the day it was built and would have sent the next reader to build it a
        // second time. What has not changed is WHERE it can be made: only after the payment has actually
        // succeeded, which is after this method has returned. So the rails still refuse — not because the
        // capability is missing, but because it is not theirs to reach.
        //
        // So the routing is REFUSED rather than half-served. Accepting it would settle the entire payment
        // on the platform account and never pay the merchant, and every signal would look healthy — a
        // successful ChargeResult, no exception, and a null transfer reference indistinguishable from one
        // that is still settling. This package tells driver authors exactly what to do here ("a driver
        // that cannot serve a routing must THROW, never no-op"), and the shipped driver has to hold to it
        // first. It is also the DEFAULT charge type, so silence would be the ordinary case, not the edge.
        if ($routing->type === ChargeType::SeparateTransfer) {
            throw MarketplaceUnsupported::separateTransferNeedsRoutedPayment();
        }

        if ($routing->onBehalfOf) {
            $params['on_behalf_of'] = $routing->destination->accountId;
        }

        return $params;
    }

    /**
     * The provider's reference for the transfer that carried the merchant's share, when it exists yet.
     *
     * On a destination charge the transfer is created when the payment settles, so an intent that still
     * needs authentication has none. Absent is therefore a stage rather than a fault, and reporting it as
     * an empty string would make a caller believe a transfer happened and was simply unnamed.
     *
     * That reading holds ONLY for a destination charge. On a separate transfer the provider creates nothing
     * on its own — the platform takes the whole payment and the merchant's share is moved by a later call,
     * which THESE RAILS do not make. `RoutedPayment` does, through `MovesMerchantShare`, once the payment
     * has actually succeeded; that is why this lane refuses the routing rather than returning a null a
     * caller would read as "settling". Null there is permanent, not pending.
     */
    private function transferOf(PaymentIntent $intent): ?string
    {
        $charge = $intent->latest_charge ?? null;

        if (! $charge instanceof Charge) {
            return null;
        }

        $transfer = $charge->transfer ?? null;

        return is_string($transfer) && $transfer !== '' ? $transfer : null;
    }

    /**
     * The provider's reference for a reversal on a refund, when it made one.
     *
     * BOTH shapes have to be read, and that is not defensive coding. The field is an id until something asks
     * for it expanded, and the refund path now does exactly that on the lane that can reverse — so reading
     * only the string would return null on the one lane where a reversal actually happens, while the
     * unexpanded lanes kept working and every test built on a stubbed id stayed green.
     */
    private function transferReversalOf(Refund $refund): ?string
    {
        $reversal = $refund->transfer_reversal ?? null;

        if ($reversal instanceof TransferReversal) {
            return $reversal->id === '' ? null : $reversal->id;
        }

        return is_string($reversal) && $reversal !== '' ? $reversal : null;
    }

    /**
     * The amount the provider actually reversed, when it says so.
     *
     * Only an EXPANDED reversal carries the figure. A bare id means the amount was not reported, which is
     * null rather than a number derived from the refund — see {@see RefundResult::$transferReversed}.
     */
    private function transferReversedIn(Refund $refund): ?Money
    {
        $reversal = $refund->transfer_reversal ?? null;

        if (! $reversal instanceof TransferReversal) {
            return null;
        }

        $currency = $reversal->currency ?? null;

        if (! is_string($currency) || $currency === '') {
            return null;
        }

        // The provider speaks lowercase currency codes; Money holds ISO-4217, which is upper case.
        return new Money($reversal->amount, strtoupper($currency));
    }

    /**
     * Run a PaymentIntent creation and translate the outcome to a neutral ChargeResult. A card decline
     * (the one recoverable business outcome) is caught and returned as a failure; any other Stripe
     * error propagates.
     *
     * @param  callable(): PaymentIntent  $create
     */
    private function settle(callable $create, Money $amount, ?ChargeRouting $routing = null): ChargeResult
    {
        try {
            $intent = $create();
        } catch (CardException $e) {
            return new ChargeResult(
                successful: false,
                reference: '',
                amount: $amount,
                failureReason: $e->getMessage(),
            );
        }

        return $this->outcomeFor($intent, $amount, $routing);
    }

    /**
     * Translate a PaymentIntent status onto the neutral outcome. Three of these are NOT declines:
     * `succeeded` is settled money; `requires_action` needs the cardholder to authenticate (3-D Secure),
     * so the client secret is carried out for the front end to confirm against; `processing` is a bank
     * debit (SEPA) still in flight. Everything else — a canceled or requires_payment_method intent — is a
     * genuine failure. Reporting an authentication or a pending debit as a decline is how a good European
     * payment gets counted as a loss.
     */
    private function outcomeFor(PaymentIntent $intent, Money $amount, ?ChargeRouting $routing = null): ChargeResult
    {
        $split = $routing instanceof ChargeRouting
            ? [
                'transferReference' => $this->transferOf($intent),
                'applicationFee' => $routing->applicationFee,
                'destination' => $routing->destination->accountId,
            ]
            : ['transferReference' => null, 'applicationFee' => null, 'destination' => null];

        return match ($intent->status) {
            'succeeded' => new ChargeResult(
                successful: true,
                reference: $intent->id,
                amount: $amount,
                transferReference: $split['transferReference'],
                applicationFee: $split['applicationFee'],
                destination: $split['destination'],
            ),
            'requires_action', 'requires_confirmation' => new ChargeResult(
                successful: false,
                reference: $intent->id,
                amount: $amount,
                failureReason: $intent->status,
                requiresAction: true,
                clientSecret: is_string($intent->client_secret) ? $intent->client_secret : null,
            ),
            'processing' => new ChargeResult(
                successful: false,
                reference: $intent->id,
                amount: $amount,
                failureReason: $intent->status,
                pending: true,
            ),
            default => new ChargeResult(
                successful: false,
                reference: $intent->id,
                amount: $amount,
                failureReason: $intent->status,
            ),
        };
    }

    /**
     * Stamp the caller's reference onto the payment itself, so it can be found again afterwards.
     *
     * The idempotency key already travels with every charge — but only as a request OPTION, which Stripe
     * uses to collapse a retry and then does not expose: there is no API that finds a payment by the key it
     * was created with. So a charge this package made could not be traced back to the cycle that made it by
     * anything other than amount and timestamp, which is guessing.
     *
     * That cost is not hypothetical. It is why a cycle whose claim was abandoned cannot be resumed
     * automatically: nothing — not this process, and not a person in the dashboard — can answer whether the
     * attempt that died mid-call left a payment behind. It is also the blind spot in every support question
     * that starts "what was this charge for".
     *
     * Metadata rather than the description: the description is shown to the customer, and an internal
     * reference is not something to put in front of them.
     *
     * The description is the OTHER half of the same field pair, and it is set here for the same reason
     * the reference is not: this is the one place both are decided, so the rule that the customer reads
     * one and never the other is visible in a single method instead of inferred from two.
     *
     * Stripe's `description` is left ABSENT rather than set to a neutral word when no narrative is given.
     * Mollie requires the field and so has a fallback; Stripe does not, and writing `Subscription` onto
     * an intent that carries no such claim would put a word on the customer's receipt that no caller
     * chose. Absent renders as the amount alone, which is what these charges have always shown.
     *
     * @param  array<array-key, mixed>  $params
     * @return array<array-key, mixed>
     */
    private function traced(array $params, ?string $idempotencyKey, ?ChargeNarrative $narrative = null): array
    {
        if ($idempotencyKey !== null) {
            $params = [...$params, 'metadata' => ['billing_reference' => $idempotencyKey]];
        }

        if ($narrative instanceof ChargeNarrative) {
            return [...$params, 'description' => $narrative->statement(self::DESCRIPTION_LIMIT)];
        }

        return $params;
    }

    /**
     * The Stripe per-request options carrying the idempotency key, or empty when none is given. A
     * stable key makes a retried money-moving request collapse onto the first result rather than
     * charging or refunding twice.
     *
     * @return array{idempotency_key?: string}
     */
    private function options(?string $idempotencyKey): array
    {
        return $idempotencyKey !== null ? ['idempotency_key' => $idempotencyKey] : [];
    }

    /**
     * Whether a payment-method type can be charged off-session (stored for reuse). Single-use,
     * redirect-only methods cannot, so a mandate over them is not reusable.
     */
    private function isReusable(string $type): bool
    {
        return ! in_array($type, ['ideal', 'bancontact', 'sofort', 'p24', 'giropay', 'eps'], true);
    }

    /**
     * The fee refund policy in force, refusing one the regime does not permit.
     *
     * The preflight checkpoint asks the same question, and that is not a duplicate check. Preflight is a
     * GATE: it answers once, on the day somebody runs it. The regime and the policy are both configuration
     * and both movable afterwards, so a platform that switches to a commission chain a month later passes no
     * gate at all — it just starts retaining fees that sit on no supply. A refund is where the money moves,
     * so a refund is where the question has to be answered again.
     *
     * It throws rather than falling back. Defaulting here would answer a question about somebody else's
     * money with whatever the package happens to prefer, on every refund, for as long as the misconfiguration
     * survives — and a refund that completed is not one anybody goes back to check.
     */
    private function feeRefundPolicy(): FeeRefundPolicy
    {
        $configured = $this->config->get('billing.marketplace.fee.refund_policy', 'refund');
        $policy = is_string($configured) ? FeeRefundPolicy::tryFrom($configured) : null;

        if (! $policy instanceof FeeRefundPolicy) {
            throw FeeRefundPolicyNotPermitted::unknown(is_string($configured) ? $configured : gettype($configured));
        }

        if (! $policy->permittedIn($this->regimes->resolveFor())) {
            throw FeeRefundPolicyNotPermitted::retainInCommissionChain();
        }

        return $policy;
    }
}
