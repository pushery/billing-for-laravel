<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Mollie;

use DateTimeInterface;
use InvalidArgumentException;
use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\Exceptions\RequestException;
use Mollie\Api\Http\Data\Date;
use Mollie\Api\Http\Requests\CancelPaymentRequest;
use Mollie\Api\Http\Requests\CreateMandateRequest;
use Mollie\Api\Http\Requests\CreatePaymentRefundRequest;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Api\Http\Requests\GetPaginatedPaymentRefundsRequest;
use Mollie\Api\Http\Requests\GetPaymentRequest;
use Mollie\Api\MollieApiClient;
use Mollie\Api\Resources\CursorCollection;
use Mollie\Api\Resources\Mandate;
use Mollie\Api\Resources\Payment;
use Mollie\Api\Resources\Refund;
use Mollie\Api\Types\MandateMethod;
use Pushery\Billing\Contracts\CancelsPayments;
use Pushery\Billing\Contracts\CollectsDirectDebits;
use Pushery\Billing\Contracts\EstablishesMandateByRedirect;
use Pushery\Billing\Contracts\PaymentRails;
use Pushery\Billing\Contracts\ReadsRefunds;
use Pushery\Billing\Enums\RefundStatus;
use Pushery\Billing\Exceptions\DirectDebitMandateRefused;
use Pushery\Billing\Exceptions\MandateNeedsRedirect;
use Pushery\Billing\Support\CheckoutUrls;
use Pushery\Billing\Support\SafeExternalUrl;
use Pushery\Billing\ValueObjects\ChargeNarrative;
use Pushery\Billing\ValueObjects\ChargeOptions;
use Pushery\Billing\ValueObjects\ChargeResult;
use Pushery\Billing\ValueObjects\ChargeRouting;
use Pushery\Billing\ValueObjects\Iban;
use Pushery\Billing\ValueObjects\MandateHandshake;
use Pushery\Billing\ValueObjects\MandateReference;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\PaymentCancellation;
use Pushery\Billing\ValueObjects\RefundResult;
use Pushery\Billing\ValueObjects\RefundState;
use Pushery\Billing\ValueObjects\SepaCreditor;
use Pushery\Billing\ValueObjects\TokenizedMethod;
use UnexpectedValueException;

/**
 * Mollie's implementation of the lower billing layer.
 *
 * Three of the five contract methods are ordinary. Two refuse, and that is the honest answer for this
 * provider rather than a gap waiting to be filled — see {@see MandateNeedsRedirect}.
 *
 * ## What "the token" means here
 *
 * `charge()` takes a `$token` that Stripe fills with a stored payment-method id. Mollie has no such thing:
 * details are captured on its own checkout, and what a caller can choose in advance is the method (`ideal`,
 * `creditcard`). So the argument carries the method, and the result is deliberately not a settled charge —
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
final readonly class MolliePaymentRails implements CancelsPayments, CollectsDirectDebits, EstablishesMandateByRedirect, PaymentRails, ReadsRefunds
{
    /**
     * Mollie's `description` carries 255 characters at most, and less for some payment methods.
     *
     * Trimmed here rather than left to the provider, because Mollie cuts the end — and the end is the
     * period, which is the half that tells two otherwise identical charges apart.
     */
    private const int DESCRIPTION_LIMIT = 255;

    /**
     * What a SEPA direct debit carries of the description: 140 characters of remittance information, the
     * EPC rulebook's limit, which Mollie cuts to rather than to its absolute 255.
     */
    private const int SEPA_DESCRIPTION_LIMIT = 140;

    /** The mandate method whose recurring payments Mollie collects as SEPA direct debits. */
    private const string SEPA_MANDATE = 'directdebit';

    /** The most entries Mollie puts on one page of a list. */
    private const int REFUND_PAGE_SIZE = 250;

    /**
     * The pages one read of a payment's refunds follows, 5,000 refunds in all. A payment carries a handful, so a list
     * that goes on past this is not ending, and the read stops with an exception rather than report part of it.
     */
    private const int REFUND_PAGE_LIMIT = 20;

    public function __construct(
        private MollieApiClient $client,
        /** Where Mollie posts its status pings: the package's webhook endpoint, which only accepts POST. */
        private string $webhookUrl,
        /** Where a customer is sent back after a checkout. Never the webhook, which a browser cannot open. */
        private CheckoutUrls $urls,
        /**
         * The profile payments are created on, which Mollie requires with an OAuth access token and ignores with
         * an API key. A payment account supplies it; the install's own driver has none.
         */
        private ?string $profileId = null,
    ) {}

    /**
     * Start an on-session payment. The result carries the checkout to send the customer to, never a
     * settled charge — at Mollie the money moves after the customer acts, not during this call.
     *
     * @param  string  $token  The Mollie method to offer (`ideal`, `creditcard`, …). See the class docblock.
     */
    public function charge(Money $amount, string $token, ?string $idempotencyKey = null, ?ChargeRouting $routing = null, ?ChargeNarrative $narrative = null, ?ChargeOptions $options = null): ChargeResult
    {
        // The key goes on the request, not only into the description. `PaymentRails` states what it is
        // for in as many words -- "pass the invoice/charge id so a re-run collapses onto the first charge
        // rather than billing the customer again" -- and putting it in `description` collapses nothing: it
        // is a label on the payment. Every retried charge was a second real payment.
        //
        // The exposure is the ordinary one, not an exotic race. An off-session charge that times out is
        // recorded as a refusal, the dunning ladder retries it with the same order id, and under Mollie the
        // customer paid twice for one cycle. Stripe's rails have always passed it as a real key, so the two
        // drivers made different promises behind one contract.
        //
        // Set immediately before the call because the SDK resets it after every request -- setting it any
        // earlier would arm whichever request happened to go first.
        //
        // The request is built before the key is armed: an install with nowhere to send the customer back to, or
        // options Mollie would refuse, are refused here, before anything reaches Mollie. A return address in the
        // options is the shop's own page of the order, and with one the install needs none.
        $request = new CreatePaymentRequest(
            description: $this->describe($narrative, 'Payment'),
            amount: MollieAmount::toMollie($amount),
            redirectUrl: $options->returnUrl ?? $this->urls->purchaseReturnUrl(),
            cancelUrl: $options?->cancelUrl,
            webhookUrl: $options->webhookUrl ?? $this->webhookUrl,
            lines: MollieChargeOptions::lines($options instanceof ChargeOptions ? $options->lines : [], $amount),
            billingAddress: MollieChargeOptions::address($options?->billingAddress),
            shippingAddress: MollieChargeOptions::address($options?->shippingAddress),
            locale: $options?->locale,
            method: MollieChargeOptions::method($token, $options),
            metadata: MollieChargeOptions::metadata($options, $this->traceOf($idempotencyKey)),
            captureMode: $options?->captureMode,
            sequenceType: MollieValue::SEQUENCE_ONEOFF,
            profileId: $this->profileId,
            additional: MollieChargeOptions::additional($options),
        );

        return $this->settle($this->keyed($idempotencyKey, fn (): mixed => $this->client->send($request)), $amount);
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
        $payment = MollieValue::narrow($this->client->send(new CreatePaymentRequest(
            description: 'Mandate verification',
            amount: MollieAmount::toMollie($verification),
            redirectUrl: $returnUrl,
            webhookUrl: $this->webhookUrl,
            sequenceType: MollieValue::SEQUENCE_FIRST,
            customerId: $customerReference,
            profileId: $this->profileId,
        )), Payment::class);

        // Narrowed as its own step rather than inside the expression below: version 3 of the SDK declares
        // `send()` as `@return mixed`, so without this the id read afterwards would be a read off anything.
        if (! $payment instanceof Payment) {
            throw MandateNeedsRedirect::noCheckoutReturned('Mollie', 'unknown');
        }

        $checkout = SafeExternalUrl::onHosts($payment->getCheckoutUrl(), MollieHostedPages::HOSTS);

        if ($checkout === null) {
            throw MandateNeedsRedirect::noCheckoutReturned('Mollie', MollieValue::id($payment->id));
        }

        return new MandateHandshake($checkout, MollieValue::id($payment->id));
    }

    /**
     * Mollie collects every direct debit under the creditor identifier of its foundation, on behalf of the merchant,
     * and its API takes no creditor identifier of the merchant's own.
     */
    public function creditor(): SepaCreditor
    {
        return new SepaCreditor('Stichting Mollie Payments', 'Keizersgracht 126, 1015 CW Amsterdam', 'NL', 'NL08ZZZ502057730000');
    }

    /**
     * Whether Mollie draws a charge against this mandate as a SEPA direct debit.
     *
     * A mandate given on the application's form is one, and so is a mandate a bank payment such as iDEAL or
     * Bancontact established, which Mollie collects as a direct debit afterwards. A card, PayPal or BACS mandate
     * is not.
     */
    public function drawsDirectDebit(MandateReference $mandate): bool
    {
        return MandateMethod::getForFirstPaymentMethod($mandate->method) === self::SEPA_MANDATE;
    }

    /**
     * Register a direct debit mandate at Mollie, with the name and IBAN the account holder confirmed.
     *
     * Mollie answers a mandate registered this way as `valid` at once. Any other status is refused here, because a
     * stored row reads as chargeable and the first debit against it would come back as a payment failure.
     */
    public function giveDirectDebitMandate(string $customerReference, string $accountHolder, Iban $iban, string $mandateReference, DateTimeInterface $signedOn): MandateReference
    {
        $mandate = MollieValue::narrow($this->client->send(new CreateMandateRequest(
            customerId: $customerReference,
            method: self::SEPA_MANDATE,
            consumerName: $accountHolder,
            consumerAccount: $iban->value,
            signatureDate: new Date($signedOn),
            mandateReference: $mandateReference,
        )), Mandate::class);

        if (! $mandate instanceof Mandate || ! $mandate->isValid()) {
            throw DirectDebitMandateRefused::notValid('Mollie', $mandate instanceof Mandate ? (MollieValue::of($mandate->status) ?? 'unknown') : 'unknown');
        }

        return new MandateReference(MollieValue::id($mandate->id), self::SEPA_MANDATE, true, $customerReference);
    }

    /**
     * Collect a due cycle against a stored mandate — the call the local engine makes.
     *
     * A mandate that is not reusable is refused here rather than at Mollie. The provider would refuse it
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
            description: $this->describe($narrative, 'Subscription', $this->recurringDescriptionLimit($mandate)),
            amount: MollieAmount::toMollie($amount),
            webhookUrl: $this->webhookUrl,
            metadata: $this->traceOf($idempotencyKey),
            sequenceType: MollieValue::SEQUENCE_RECURRING,
            mandateId: $mandate->id,
            customerId: $mandate->customerReference,
            profileId: $this->profileId,
        )));

        return $this->settle($payment, $amount);
    }

    /**
     * Refund a previous payment, in full or in part.
     *
     * A Mollie refund is `queued` or `pending` when it is created and only later `refunded`. That is
     * reported as success, unlike the charge mapping above, and the asymmetry is deliberate: an accepted
     * refund is a commitment the provider has taken on, and reporting it as unsuccessful would invite the
     * caller to try again — which is a second refund.
     */
    public function refund(string $chargeReference, Money $amount, ?string $idempotencyKey = null, ?ChargeRouting $routing = null): RefundResult
    {
        try {
            $refund = $this->keyed($idempotencyKey, fn (): mixed => $this->client->send(new CreatePaymentRefundRequest(
                paymentId: $chargeReference,
                // 'Refund', not the reference. Mollie shows a refund's description to the customer, the same
                // way it shows a payment's — so this field leaked an internal id onto the statement of somebody
                // getting their money back, which is the worst moment to look disorganized. The two money calls
                // were corrected when the reference moved to metadata; this one was missed because a refund is
                // not a charge, and the sentence that scoped it out ("charges carry the reference") was true of
                // the metadata and silent about the leak.
                description: 'Refund',
                amount: MollieAmount::toMollie($amount),
                metadata: $this->traceOf($idempotencyKey),
            )));
        } catch (RequestException $refusal) {
            // Mollie refused this refund: more than is left to refund, a payment that cannot be refunded, an id it
            // does not know. That is an answer, and it comes back as one with Mollie's own words. A refusal that
            // says nothing about the refund (credentials, a rate limit, an outage) travels, so it is retried.
            $status = $refusal->getStatusCode();

            if ($status < 400 || $status >= 500 || in_array($status, [401, 403, 429], true)) {
                throw $refusal;
            }

            return new RefundResult(
                successful: false,
                reference: '',
                amount: $amount,
                failureCode: (string) $status,
                failureMessage: $refusal instanceof ApiException ? $refusal->getPlainMessage() : $refusal->getMessage(),
            );
        }

        if ($refund instanceof Refund && ($refund->isFailed() || $refund->isCanceled())) {
            return new RefundResult(
                successful: false,
                reference: MollieValue::id($refund->id),
                amount: $amount,
                failureCode: MollieValue::of($refund->status),
            );
        }

        return new RefundResult(
            successful: true,
            reference: $refund instanceof Refund ? MollieValue::id($refund->id) : '',
            amount: $amount,
        );
    }

    /**
     * Cancel a payment the customer has not completed, in the client's mode: an account's rails in test mode cancel
     * the test payment they made.
     *
     * Mollie cancels a payment while its method allows it, an `open` one in most cases, and keeps one whose customer
     * has not chosen a method yet, `isCancelable: false` in its reference. One it keeps answers 422, and
     * that comes back as a cancellation that did not happen, with Mollie's own words. So does an id Mollie does not
     * know. A refusal that says nothing about the payment (credentials, a rate limit, an outage) travels, as a refund's
     * does.
     */
    public function cancel(string $paymentReference): PaymentCancellation
    {
        try {
            $payment = MollieValue::narrow($this->client->send(new CancelPaymentRequest($paymentReference)), Payment::class);
        } catch (RequestException $refusal) {
            $status = $refusal->getStatusCode();

            if ($status < 400 || $status >= 500 || in_array($status, [401, 403, 429], true)) {
                throw $refusal;
            }

            return new PaymentCancellation(
                paymentReference: $paymentReference,
                canceled: false,
                failureCode: (string) $status,
                failureMessage: $refusal instanceof ApiException ? $refusal->getPlainMessage() : $refusal->getMessage(),
            );
        }

        if (! $payment instanceof Payment) {
            throw new UnexpectedValueException("Mollie answered the cancellation of payment {$paymentReference} with something other than a payment.");
        }

        return $payment->isCanceled()
            ? new PaymentCancellation($paymentReference, canceled: true)
            : new PaymentCancellation($paymentReference, canceled: false, failureCode: MollieValue::of($payment->status));
    }

    /**
     * Every refund Mollie holds for the payment, read page by page.
     *
     * An answer that is not a list of refunds is refused rather than read as an empty one. A caller looks for its
     * key here before it refunds again, and a list read as empty would send it to refund a second time.
     *
     * @return list<RefundState>
     */
    public function refundsOf(string $chargeReference): array
    {
        $states = [];
        $page = MollieValue::narrow($this->client->send(new GetPaginatedPaymentRefundsRequest($chargeReference, limit: self::REFUND_PAGE_SIZE)), CursorCollection::class);

        for ($read = 1; ; $read++) {
            if (! $page instanceof CursorCollection) {
                throw new UnexpectedValueException("Mollie answered the refunds of payment {$chargeReference} with something other than a list, so they are unknown rather than absent.");
            }

            foreach ($page as $refund) {
                $states[] = $refund instanceof Refund
                    ? $this->stateOf($refund)
                    : throw new UnexpectedValueException("Mollie's list of the refunds of payment {$chargeReference} holds an entry of another kind.");
            }

            if (! $page->hasNext()) {
                return $states;
            }

            if ($read >= self::REFUND_PAGE_LIMIT) {
                throw new UnexpectedValueException('Mollie lists more than '.(self::REFUND_PAGE_SIZE * self::REFUND_PAGE_LIMIT)." refunds for payment {$chargeReference}, so the list was not read to its end.");
            }

            $page = MollieValue::narrow($page->next(), CursorCollection::class);
        }
    }

    /**
     * Mollie's own figure for what is left to refund, or null where it names none: before the payment is paid, and on
     * a method Mollie takes no refunds for.
     */
    public function refundableOf(string $chargeReference): ?Money
    {
        $payment = MollieValue::narrow($this->client->send(new GetPaymentRequest($chargeReference)), Payment::class);

        if (! $payment instanceof Payment) {
            throw new UnexpectedValueException("Mollie answered payment {$chargeReference} with something other than a payment.");
        }

        return MollieAmount::fromNullableResource($payment->amountRemaining);
    }

    /**
     * One refund in the words every provider shares, with the key {@see self::refund()} stored in its metadata.
     *
     * `queued`, `pending` and `processing` all read as pending, and so does a status Mollie adds later: a refund
     * that may still pay out is not one to make a second time.
     */
    private function stateOf(Refund $refund): RefundState
    {
        return new RefundState(
            reference: MollieValue::id($refund->id),
            key: $this->keyIn($refund->metadata),
            status: match (MollieValue::of($refund->status)) {
                'refunded' => RefundStatus::Refunded,
                'failed' => RefundStatus::Failed,
                'canceled' => RefundStatus::Canceled,
                default => RefundStatus::Pending,
            },
            amount: MollieAmount::fromResource($refund->amount),
        );
    }

    /**
     * The key {@see self::refund()} stored in a refund's metadata, or null where it stored none.
     *
     * The parameter stays `mixed`, because the two majors of the SDK disagree about the type: version 4 declares the
     * metadata as an object, version 3 leaves it as the JSON decoder made it.
     */
    private function keyIn(mixed $metadata): ?string
    {
        $values = is_object($metadata) ? get_object_vars($metadata) : (is_array($metadata) ? $metadata : []);
        $key = $values[ChargeOptions::RESERVED_METADATA_KEY] ?? null;

        return is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * The caller's reference, for the payment's metadata rather than for its description.
     *
     * The description is the wrong field for it, twice over. It is the text Mollie shows the customer — so a
     * subscriber would see a bare internal number where the name of the thing they bought belongs — and it is
     * not a place anything can search, so a charge could not be traced back to the cycle that made it.
     *
     * Metadata answers both halves at once: the number goes into a machine field, and the description is
     * a word a person can read. That word is the service and the period where the caller knows them
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
    private function describe(?ChargeNarrative $narrative, string $fallback, int $limit = self::DESCRIPTION_LIMIT): string
    {
        return $narrative?->statement($limit) ?? $fallback;
    }

    /**
     * The description limit of a recurring payment, which Mollie collects on its mandate's rails.
     *
     * The stored method is the first payment's, and the SDK maps it onto the mandate it established: a card or
     * Apple Pay onto a card mandate, PayPal and BACS onto their own, and iDEAL, Bancontact and every other bank
     * method onto a SEPA direct-debit mandate. A method the SDK does not know lands on direct debit as well,
     * which costs a shorter service name at worst and keeps the period.
     */
    private function recurringDescriptionLimit(MandateReference $mandate): int
    {
        return MandateMethod::getForFirstPaymentMethod($mandate->method) === self::SEPA_MANDATE
            ? self::SEPA_DESCRIPTION_LIMIT
            : self::DESCRIPTION_LIMIT;
    }

    /**
     * Run one request with the idempotency key armed, and disarm it afterwards whatever happened.
     *
     * The client is a singleton, so a key still set after this call would go out with the next request: a
     * customer creation could come back as the previous charge, or be refused for a payload that does not
     * match the key it inherited. Version 4 of the SDK takes the key off the client while it prepares a
     * request, so a request that is sent leaves nothing behind; a call that throws before that point, the SDK
     * refusing its own arguments for one, leaves the key set, and this driver also accepts version 3.13.
     *
     * A key that leaks to the next request is worse than no key at all. `finally` holds whichever way the
     * call ends and whichever SDK made it, where a second reset after the call would hold only for the call
     * that returns.
     *
     * The answer stays `mixed` on purpose. Each caller checks what came back before it reads it, because the
     * SDK hands over what the response hydrated to, and an answer of another shape is a case they handle.
     *
     * @param  callable(): mixed  $send
     */
    private function keyed(?string $idempotencyKey, callable $send): mixed
    {
        $this->client->setIdempotencyKey($idempotencyKey);

        try {
            return $send();
        } finally {
            $this->client->resetIdempotencyKey();
        }
    }

    /** Translate a Mollie payment into the neutral outcome, keeping "not yet" apart from "no". */
    private function settle(mixed $payment, Money $amount): ChargeResult
    {
        if (! $payment instanceof Payment) {
            return new ChargeResult(false, '', $amount, 'unexpected_response');
        }

        $reference = MollieValue::id($payment->id);

        if ($payment->isPaid()) {
            return new ChargeResult(true, $reference, $amount);
        }

        if ($payment->isFailed() || $payment->isCanceled() || $payment->isExpired()) {
            return new ChargeResult(false, $reference, $amount, $this->reasonFor($payment));
        }

        $checkout = SafeExternalUrl::onHosts($payment->getCheckoutUrl(), MollieHostedPages::HOSTS);

        // A checkout URL means the customer still has to act; without one the payment is simply in flight
        // (a bank debit settling over days). Both are "not yet", and neither is a decline — but only one
        // of them has somewhere to send the customer. A checkout on a host Mollie serves none from counts as
        // none, so the payment waits for the provider rather than sending anybody there.
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
     * quote, and an empty code quotes nothing either. The status is then the reason, which is more useful
     * than a placeholder saying we do not know.
     */
    private function reasonFor(Payment $payment): string
    {
        return MollieValue::reasonCode($payment->statusReason) ?? MollieValue::of($payment->status) ?? '';
    }
}
