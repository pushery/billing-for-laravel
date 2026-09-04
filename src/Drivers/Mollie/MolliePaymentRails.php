<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Mollie;

use InvalidArgumentException;
use Mollie\Api\Http\Requests\CreatePaymentRefundRequest;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Api\MollieApiClient;
use Mollie\Api\Resources\Payment;
use Mollie\Api\Resources\Refund;
use Mollie\Api\Types\SequenceType;
use Pushery\Billing\Contracts\EstablishesMandateByRedirect;
use Pushery\Billing\Contracts\PaymentRails;
use Pushery\Billing\Exceptions\MandateNeedsRedirect;
use Pushery\Billing\ValueObjects\ChargeNarrative;
use Pushery\Billing\ValueObjects\ChargeResult;
use Pushery\Billing\ValueObjects\ChargeRouting;
use Pushery\Billing\ValueObjects\MandateHandshake;
use Pushery\Billing\ValueObjects\MandateReference;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\RefundResult;
use Pushery\Billing\ValueObjects\TokenizedMethod;

/**
 * Mollie's implementation of the lower billing layer.
 *
 * Three of the five contract methods are ordinary. Two REFUSE, and that is the honest answer for this
 * provider rather than a gap waiting to be filled — see {@see MandateNeedsRedirect}.
 *
 * ## What "the token" means here
 *
 * `charge()` takes a `$token` that Stripe fills with a stored payment-method id. Mollie has no such thing:
 * details are captured on its own checkout, and what a caller can choose in advance is the METHOD (`ideal`,
 * `creditcard`). So the argument carries the method, and the result is deliberately NOT a settled charge —
 * it is `requiresAction` with the checkout URL the customer still has to visit.
 *
 * Putting a URL in `clientSecret` stretches that field's name, which was written for a Stripe intent
 * secret. It is the right slot all the same: the field means "what the front end needs next", and the
 * alternative — a second, driver-shaped field on a value object every driver returns — is how a neutral
 * contract stops being neutral.
 *
 * ## The status mapping, and why three outcomes rather than two
 *
 * A SEPA direct debit sits at `open` for days before it settles. Collapsing that onto failure would start
 * a dunning ladder against money that is on its way and cancel a subscription somebody paid for. Only
 * `paid` is success; `failed`, `canceled` and `expired` are declines; everything else is `pending`, which
 * the engine reads as "not yet" rather than as "no".
 */
final readonly class MolliePaymentRails implements EstablishesMandateByRedirect, PaymentRails
{
    /**
     * Mollie's `description` carries 255 characters.
     *
     * Trimmed HERE rather than left to the provider, because Mollie cuts the end — and the end is the
     * period, which is the half that tells two otherwise identical charges apart.
     */
    private const int DESCRIPTION_LIMIT = 255;

    public function __construct(
        private MollieApiClient $client,
        /** Where Mollie returns the customer after a redirect, and where it posts its status pings. */
        private string $returnUrl,
    ) {}

    /**
     * Start an on-session payment. The result carries the checkout to send the customer to, never a
     * settled charge — at Mollie the money moves after the customer acts, not during this call.
     *
     * @param  string  $token  The Mollie METHOD to offer (`ideal`, `creditcard`, …). See the class docblock.
     */
    public function charge(Money $amount, string $token, ?string $idempotencyKey = null, ?ChargeRouting $routing = null, ?ChargeNarrative $narrative = null): ChargeResult
    {
        // THE KEY GOES ON THE REQUEST, not only into the description. `PaymentRails` states what it is
        // for in as many words -- "pass the invoice/charge id so a re-run collapses onto the first charge
        // rather than billing the customer again" -- and putting it in `description` collapses nothing: it
        // is a label on the payment. Every retried charge was a SECOND real payment.
        //
        // The exposure is the ordinary one, not an exotic race. An off-session charge that times out is
        // recorded as a refusal, the dunning ladder retries it with the same order id, and under Mollie the
        // customer paid twice for one cycle. Stripe's rails have always passed it as a real key, so the two
        // drivers made different promises behind one contract.
        //
        // Set immediately before the call because the SDK resets it after every request -- setting it any
        // earlier would arm whichever request happened to go first.
        $payment = $this->keyed($idempotencyKey, fn (): mixed => $this->client->send(new CreatePaymentRequest(
            description: $this->describe($narrative, 'Payment'),
            amount: MollieAmount::toMollie($amount),
            redirectUrl: $this->returnUrl,
            webhookUrl: $this->returnUrl,
            method: $token,
            metadata: $this->traceOf($idempotencyKey),
            sequenceType: SequenceType::ONEOFF,
        )));

        return $this->settle($payment, $amount);
    }

    /**
     * Not available at Mollie, and not because it is unbuilt.
     *
     * @throws MandateNeedsRedirect always
     */
    public function createMandate(string $customerReference, string $token): MandateReference
    {
        throw MandateNeedsRedirect::forDriver('Mollie');
    }

    /**
     * Not available at Mollie, and not because it is unbuilt.
     *
     * @throws MandateNeedsRedirect always
     */
    public function tokenize(string $paymentData): TokenizedMethod
    {
        throw MandateNeedsRedirect::tokenizationUnsupported('Mollie');
    }

    /**
     * Begin establishing a mandate: a `first` payment the customer completes on Mollie's checkout.
     *
     * The mandate does not exist when this returns. What exists is a payment in progress and somewhere to
     * send somebody — which is why the return type is a handshake rather than a reference: a reference
     * would invite a caller to store it as a mandate and charge against it.
     */
    public function beginMandate(string $customerReference, Money $verification, string $returnUrl): MandateHandshake
    {
        $payment = $this->client->send(new CreatePaymentRequest(
            description: 'Mandate verification',
            amount: MollieAmount::toMollie($verification),
            redirectUrl: $returnUrl,
            webhookUrl: $this->returnUrl,
            sequenceType: SequenceType::FIRST,
            customerId: $customerReference,
        ));

        // Narrowed as its own step rather than inside the expression below: `send()` is declared
        // `@return mixed`, so without this the id read afterwards would be a read off anything.
        if (! $payment instanceof Payment) {
            throw MandateNeedsRedirect::noCheckoutReturned('Mollie', 'unknown');
        }

        $checkout = $payment->getCheckoutUrl();

        if ($checkout === null) {
            throw MandateNeedsRedirect::noCheckoutReturned('Mollie', (string) $payment->id);
        }

        return new MandateHandshake($checkout, (string) $payment->id);
    }

    /**
     * Collect a due cycle against a stored mandate — the call the local engine makes.
     *
     * A mandate that is not reusable is refused HERE rather than at Mollie. The provider would refuse it
     * too, but at the cost of a round trip on every due cycle of a subscriber whose mandate was revoked —
     * and each refusal reads as a payment failure in the log rather than as the settled fact it is.
     */
    public function offSessionCharge(Money $amount, MandateReference $mandate, ?string $idempotencyKey = null, ?ChargeRouting $routing = null, ?ChargeNarrative $narrative = null): ChargeResult
    {
        if (! $mandate->reusable) {
            throw new InvalidArgumentException(
                "Mandate {$mandate->id} is not reusable, so it cannot be charged off-session. A revoked or ".
                'one-off mandate reaching this call means the caller read a stored row without checking its '.
                'status; charging it would only produce a refusal that reads like the subscriber failing to pay.'
            );
        }

        $payment = $this->keyed($idempotencyKey, fn (): mixed => $this->client->send(new CreatePaymentRequest(
            description: $this->describe($narrative, 'Subscription'),
            amount: MollieAmount::toMollie($amount),
            webhookUrl: $this->returnUrl,
            metadata: $this->traceOf($idempotencyKey),
            sequenceType: SequenceType::RECURRING,
            mandateId: $mandate->id,
            customerId: $mandate->customerReference,
        )));

        return $this->settle($payment, $amount);
    }

    /**
     * Refund a previous payment, in full or in part.
     *
     * A Mollie refund is `queued` or `pending` when it is created and only later `refunded`. That is
     * reported as SUCCESS, unlike the charge mapping above, and the asymmetry is deliberate: an accepted
     * refund is a commitment the provider has taken on, and reporting it as unsuccessful would invite the
     * caller to try again — which is a second refund.
     */
    public function refund(string $chargeReference, Money $amount, ?string $idempotencyKey = null, ?ChargeRouting $routing = null): RefundResult
    {
        $refund = $this->keyed($idempotencyKey, fn (): mixed => $this->client->send(new CreatePaymentRefundRequest(
            paymentId: $chargeReference,
            // 'Refund', not the reference. Mollie shows a refund's description to the CUSTOMER, the same
            // way it shows a payment's — so this field leaked an internal id onto the statement of somebody
            // getting their money back, which is the worst moment to look disorganized. The two money calls
            // were corrected when the reference moved to metadata; this one was missed because a refund is
            // not a charge, and the sentence that scoped it out ("charges carry the reference") was true of
            // the metadata and silent about the leak.
            description: 'Refund',
            amount: MollieAmount::toMollie($amount),
            metadata: $this->traceOf($idempotencyKey),
        )));

        return new RefundResult(
            successful: ! $refund instanceof Refund || ! $refund->isFailed() && ! $refund->isCanceled(),
            reference: $refund instanceof Refund ? (string) $refund->id : '',
            amount: $amount,
        );
    }

    /** Translate a Mollie payment into the neutral outcome, keeping "not yet" apart from "no". */
    /**
     * Run one request with the idempotency key armed, and disarm it afterwards WHATEVER happened.
     *
     * The SDK clears the key in a RESPONSE middleware, so it clears it only when a response comes back. A
     * request that throws — a network error, an API error, a timeout, which is precisely the case an
     * idempotency key exists for — leaves it set. And the client is a singleton: the next call would send
     * somebody else's key, so a customer creation could come back as the previous charge, or be refused
     * for a payload that does not match the key it inherited.
     *
     * A key that leaks to the next request is worse than no key at all, and the leak is loudest exactly
     * when the first request failed. `finally`, not a second reset after the call.
     *
     * @template T
     *
     * @param  callable(): T  $send
     * @return T
     */
    /**
     * The caller's reference, for the payment's metadata rather than for its description.
     *
     * The description used to carry it, and that was two mistakes in one field. It is the text Mollie shows
     * the CUSTOMER — so a subscriber saw a bare internal number where the name of the thing they bought
     * belonged — and it is not a place anything can search, so a charge still could not be traced back to
     * the cycle that made it.
     *
     * Metadata fixes both halves at once: the number moves to a machine field, and the description becomes
     * a word a person can read. That word is now the SERVICE and the period where the caller knows them
     * ({@see describe()}); this method stays about the machine field, and the two must never swap.
     *
     * @return ?array<string, string>
     */
    private function traceOf(?string $idempotencyKey): ?array
    {
        return $idempotencyKey === null ? null : ['billing_reference' => $idempotencyKey];
    }

    /**
     * The text Mollie shows the customer: what they bought and for when, or the neutral fallback.
     *
     * The fallback is not a placeholder for missing work — it is the honest answer where the caller knows
     * nothing beyond "a payment happened", which is every call that is not a subscription cycle. Naming a
     * service there would mean inventing one.
     */
    private function describe(?ChargeNarrative $narrative, string $fallback): string
    {
        return $narrative?->statement(self::DESCRIPTION_LIMIT) ?? $fallback;
    }

    private function keyed(?string $idempotencyKey, callable $send): mixed
    {
        $this->client->setIdempotencyKey($idempotencyKey);

        try {
            return $send();
        } finally {
            $this->client->resetIdempotencyKey();
        }
    }

    private function settle(mixed $payment, Money $amount): ChargeResult
    {
        if (! $payment instanceof Payment) {
            return new ChargeResult(false, '', $amount, 'unexpected_response');
        }

        $reference = (string) $payment->id;

        if ($payment->isPaid()) {
            return new ChargeResult(true, $reference, $amount);
        }

        if ($payment->isFailed() || $payment->isCanceled() || $payment->isExpired()) {
            return new ChargeResult(false, $reference, $amount, $this->reasonFor($payment));
        }

        $checkout = $payment->getCheckoutUrl();

        // A checkout URL means the customer still has to act; without one the payment is simply in flight
        // (a bank debit settling over days). Both are "not yet", and neither is a decline — but only one
        // of them has somewhere to send the customer.
        return new ChargeResult(
            successful: false,
            reference: $reference,
            amount: $amount,
            requiresAction: $checkout !== null,
            pending: $checkout === null,
            clientSecret: $checkout,
        );
    }

    /**
     * Mollie's own words for why it refused, kept rather than replaced with ours.
     *
     * Not every decline carries one: a canceled or expired payment simply ran out, and there is nothing to
     * quote. The status is then the reason, which is more useful than a placeholder saying we do not know.
     */
    private function reasonFor(Payment $payment): string
    {
        $reason = $payment->statusReason;

        if (is_object($reason) && isset($reason->code) && is_string($reason->code)) {
            return $reason->code;
        }

        return $payment->status;
    }
}
