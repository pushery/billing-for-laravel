<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Mollie;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Mollie\Api\Exceptions\RequestException;
use Mollie\Api\Http\Requests\GetMandateRequest;
use Mollie\Api\Http\Requests\GetPaymentRequest;
use Mollie\Api\MollieApiClient;
use Mollie\Api\Resources\Mandate;
use Mollie\Api\Resources\Payment;
use Mollie\Api\Types\MandateMethod;
use Pushery\Billing\Contracts\DerivesDeliveryKey;
use Pushery\Billing\Contracts\WebhookEventMapper;
use Pushery\Billing\Enums\ReversalCause;
use Pushery\Billing\Events\AddonPurchased;
use Pushery\Billing\Events\AddonRefunded;
use Pushery\Billing\Events\ChargebackReceived;
use Pushery\Billing\Events\InPersonSaleCanceled;
use Pushery\Billing\Events\InPersonSalePaid;
use Pushery\Billing\Events\MandateEstablished;
use Pushery\Billing\Events\PaymentFailed;
use Pushery\Billing\Events\PaymentSucceeded;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\PaymentMethod;
use Throwable;

/**
 * Turns Mollie's bare status ping into the package's neutral events — by reading the truth back, never by
 * believing the ping.
 *
 * The ping names a resource and says nothing trustworthy about it. Anybody who can reach the endpoint can
 * post any body they like, so the only content that counts is what Mollie answers when asked about that id
 * with our own key. Trusting the posted status would let a stranger mark a subscriber delinquent with one
 * request; fetching makes a forged ping produce exactly what a genuine one would.
 *
 * Silence is a normal answer here, and twice over. Mollie pings on every transition, including ones with
 * no neutral meaning — a bank debit that is still settling is not news, and emitting a failure for it would
 * start a dunning ladder against money on its way. And an id that does not resolve produces nothing at all,
 * which is the same answer a forged ping and a redelivered test payment both deserve.
 */
final class MollieWebhookEventMapper implements DerivesDeliveryKey, WebhookEventMapper
{
    /** The mandate method of a card, whose details name the card's expiry. */
    private const string CARD_MANDATE = 'creditcard';

    public function __construct(private readonly MollieApiClient $client) {}

    /**
     * Payments already read back during this request, memoized so the key and the mapping share one round trip.
     *
     * @var array<string, ?Payment>
     */
    private array $fetched = [];

    /**
     * Mollie pings with a bare resource id, so the id alone cannot say whether this is a redelivery.
     *
     * The same `tr_…` arrives when the payment opens, when it is paid, and when it is later refunded.
     * Keyed on the id alone, everything after the first ping reads as a duplicate and is dropped — a
     * payment that succeeds after an `open` ping would book nothing, silently. The status is what makes
     * two pings about the same resource distinguishable, so it belongs in the key.
     *
     * A refund or a chargeback leaves the status as it was, so every ping about one arrives under the key of
     * the ping before. The effects that act on them dedupe on what the event reports instead, the cumulative
     * refunded total or the chargeback, and a second refund lands under the same key as the first.
     *
     * Null for anything this mapper would not map anyway: a next-generation event, an id that names no
     * payment, a resource that does not resolve. The receiver then falls back to its own key, which
     * records the delivery exactly once rather than once per retry.
     */
    public function deliveryKey(Request $request): ?string
    {
        $id = $this->entityIdOf($request);

        if ($id === null || ! $this->namesAPayment($id)) {
            return null;
        }

        $payment = $this->fetch($id);

        if (! $payment instanceof Payment) {
            return null;
        }

        $status = trim(MollieValue::of($payment->status) ?? '');
        $status = $status !== '' ? $status : 'unknown';

        return $id.':'.$status;
    }

    /**
     * Which resource this ping is about, across both of Mollie's webhook generations.
     *
     * The legacy ping is a form with a single `id` field naming the payment. The next generation is signed
     * JSON describing an event, and it carries BOTH: `id` is the event (`evt_…`) and `entityId` is the
     * resource the event happened to. So `entityId` is read first — the other order fetches the event id as
     * if it were a payment, and the failure mode is silence rather than an error, because the fetch simply
     * does not resolve and this class is built to say nothing about an id it cannot follow.
     *
     * Both generations then run the SAME path from here, which is the reason this returns an id rather than
     * branching. Two paths would be two behaviors to keep in step, and the one that runs on fewer installs
     * is the one that would quietly fall behind.
     *
     * The SDK ships a mapper for the next-generation payload and it is deliberately not used: its map
     * covers the event types it knows and THROWS on anything else, and ordinary payment transitions are not
     * among them — the events this package exists for are the ones it would refuse.
     */
    private function entityIdOf(Request $request): ?string
    {
        $entityId = $request->input('entityId');

        if (is_string($entityId) && trim($entityId) !== '') {
            return trim($entityId);
        }

        // The fallback belongs to the LEGACY ping alone, and `type` is what tells the two apart: a legacy
        // body has no event type, so `id` there names the payment. A next-generation event that reached
        // this line is malformed — it announced a type and then named no entity — and falling back would
        // spend an API round trip asking Mollie about an `evt_…` id that cannot be a payment. Under a
        // redelivery storm that is a real cost for a request that was never going to produce anything.
        if ($request->input('type') !== null) {
            return null;
        }

        $id = $request->input('id');

        return is_string($id) && trim($id) !== '' ? trim($id) : null;
    }

    /** @return iterable<AddonPurchased|AddonRefunded|ChargebackReceived|InPersonSaleCanceled|InPersonSalePaid|MandateEstablished|PaymentFailed|PaymentSucceeded> */
    public function map(Request $request): iterable
    {
        // Answered BEFORE the id is looked at, because the id cannot answer it. A next-generation delivery
        // names its own kind of entity — `dis_…`, `po_…` — and running that through the payment check
        // produces a warning about the id when the real subject is the TYPE, which somebody has already
        // decided about.
        if ($this->isDecidedAgainst($request)) {
            return;
        }

        $id = $this->entityIdOf($request);

        if ($id === null) {
            return;
        }

        if (! $this->namesAPayment($id)) {
            return;
        }

        $payment = $this->fetch($id);

        if (! $payment instanceof Payment) {
            return;
        }

        if ($this->isCounterSale($payment)) {
            yield from $this->counterSaleEvents($payment);

            return;
        }

        $amount = MollieAmount::fromResource($payment->amount);
        $customer = is_string($payment->customerId) ? $payment->customerId : '';

        if ($payment->isPaid()) {
            yield new PaymentSucceeded($customer, $amount, MollieValue::id($payment->id));

            yield from $this->addonPurchaseOf($payment, $customer, $amount);

            yield from $this->mandateOf($payment, $customer);

            $chargebacks = $this->chargebacksOf($payment);

            yield from $this->refundOf($payment, $amount, $chargebacks);

            yield from MollieChargebackEvents::from($chargebacks, $customer, MollieValue::id($payment->id));

            return;
        }

        if ($payment->isFailed() || $payment->isCanceled() || $payment->isExpired()) {
            yield new PaymentFailed($customer, $amount, MollieValue::id($payment->id));

            return;
        }

        $this->noteUnmappedStatus($payment);
    }

    /**
     * Whether this payment is a sale the package put on a terminal, told apart by the marker it set.
     *
     * Such a payment belongs to no customer and to no billing cycle, so reading it as an ordinary payment would
     * report a success nothing could settle. Its confirmation goes to the sale instead.
     */
    private function isCounterSale(Payment $payment): bool
    {
        $metadata = $payment->metadata;

        return is_object($metadata) && ($metadata->{MollieCardPresentPayments::SALE_MARKER} ?? null) === '1';
    }

    /**
     * The events of a sale at the counter: paid, or over without being paid.
     *
     * A declined card, a terminal the payment never reached and a buyer who walked away all end the payment at
     * Mollie, so each one closes the sale. A payment still open says nothing yet.
     *
     * @return iterable<InPersonSaleCanceled|InPersonSalePaid>
     */
    private function counterSaleEvents(Payment $payment): iterable
    {
        if ($payment->isPaid()) {
            yield new InPersonSalePaid('mollie', MollieValue::id($payment->id), MollieAmount::fromResource($payment->amount));

            return;
        }

        if ($payment->isFailed() || $payment->isCanceled() || $payment->isExpired()) {
            yield new InPersonSaleCanceled('mollie', MollieValue::id($payment->id));
        }
    }

    /**
     * Say something about a status this mapper does not act on — but only about the ones nobody chose.
     *
     * There are two kinds of silence here and only one of them is a problem. `open`, `pending` and
     * `authorized` are silent BY DECISION: a SEPA debit sits on `open` for days, and treating that as a
     * failure would start a dunning ladder against money that is on its way. Logging those would write a
     * line on a large share of all deliveries, and a log nobody can read is the same as no log.
     *
     * A status in neither list is the one worth a line. Mollie added it, this package has never seen it,
     * and it currently produces exactly the same nothing as a settling debit — so a provider change stays
     * invisible until somebody notices money missing. That is the silent no-op this warning exists to end.
     */
    private function noteUnmappedStatus(Payment $payment): void
    {
        // The strings Mollie sends, not the SDK's constants for them: version 4 renamed every one of those
        // constants and hands the status back as an enum, while the wire value stayed the same.
        $inert = [MollieValue::PAYMENT_OPEN, MollieValue::PAYMENT_PENDING, MollieValue::PAYMENT_AUTHORIZED];
        $status = MollieValue::of($payment->status);

        if (in_array($status, $inert, true)) {
            return;
        }

        Log::warning('billing: unmapped Mollie payment status, so this delivery produced no event', [
            'payment' => MollieValue::id($payment->id),
            'status' => $status,
        ]);
    }

    /**
     * Whether this delivery announces a next-generation type this package deliberately does not act on.
     *
     * Three states, and the middle one is what this adds. A type that was CONSIDERED and declined passes
     * silently; a type the SDK knows and nobody here has classified WARNS, because Mollie adding an event
     * and this package never noticing is the case worth a line. An unrecognized type is not this method's
     * business at all — it falls through to the id checks, where a forged or malformed body belongs.
     *
     * Before this, every next-generation delivery produced "named a resource that is not a payment": a
     * warning fired by the ordinary case, which is a warning nobody reads by the time it matters.
     */
    private function isDecidedAgainst(Request $request): bool
    {
        $type = $request->input('type');

        if (! is_string($type) || trim($type) === '') {
            return false;
        }

        $type = trim($type);

        // A family the mapper acts on goes on to the mapping, whether the installed SDK lists the type yet or not:
        // a `payment.paid` names the payment it is about, and dropping it with a warning once the SDK learned its
        // name would lose the status for a host that subscribed to it.
        if (MollieNextGenEventTypes::actedOn($type) || ! MollieNextGenEventTypes::known($type)) {
            return false;
        }

        $reason = MollieNextGenEventTypes::decidedAgainst($type);

        if ($reason === null) {
            Log::warning('billing: Mollie sent a webhook event type this package has never classified', [
                'type' => $type,
                'entity' => $request->input('entityId'),
            ]);

            return true;
        }

        return true;
    }

    /**
     * Whether this id names a payment at all — and a line in the log when it does not.
     *
     * Mollie's legacy webhook posts a bare id, and not every id it posts is a payment: a refund (`rfd_`), a
     * subscription (`sub_`), a chargeback (`chb_`) and a mandate (`mdt_`) all arrive through the same one
     * field. Fetching those as a payment produces a failed call and then silence — indistinguishable from
     * a forged ping, so an install receiving them would see nothing at all and have nothing to search for.
     *
     * Two things follow. The round trip is not spent, because the prefix already answers the question the
     * call would ask. And the drop carries the kind, so it can be found — the same property the unmapped
     * status warning exists for, one level earlier.
     *
     * Deliberately a prefix check rather than a resolver: following a refund id would mean fetching the
     * refund, then its payment, then deciding whether that is the same event the payment's own ping already
     * produced. That is its own piece of work, and guessing at it here would emit the same refund twice.
     */
    private function namesAPayment(string $id): bool
    {
        if (str_starts_with($id, 'tr_')) {
            return true;
        }

        $kind = str_contains($id, '_') ? strstr($id, '_', true) : $id;

        Log::warning('billing: Mollie webhook named a resource that is not a payment, so nothing was done', [
            'kind' => $kind,
            'reference' => $id,
        ]);

        return false;
    }

    /**
     * The mandate this payment granted, if it granted one.
     *
     * Mollie has no SetupIntent: recurring capability is established by a `sequenceType: first` payment the
     * customer completes on checkout, and the mandate exists only once that payment is paid. So this is
     * where a Mollie subscriber BECOMES billable — and it is here, on the webhook, rather than on the
     * return redirect, because the redirect happens in a browser that may never come back while the
     * webhook fires either way. A customer who pays and closes the tab is otherwise left holding a valid
     * mandate the package does not know about, and is dunned for it at the first renewal.
     *
     * Three conditions, and each excludes a wrong row rather than a rare one. `recurring` payments carry
     * the same mandate on every renewal, so recording them writes one row per cycle for a mandate granted
     * once. An unpaid first payment carries a mandate the bank never granted. And a paid first payment can
     * legitimately arrive with no mandate id at all — for some methods Mollie creates it asynchronously —
     * which is a case to leave to the redelivery, not to fill in.
     *
     * @return iterable<MandateEstablished>
     */
    private function mandateOf(Payment $payment, string $customer): iterable
    {
        if (! $payment->hasSequenceTypeFirst()) {
            return;
        }

        $mandateId = $payment->mandateId;

        if (! is_string($mandateId) || trim($mandateId) === '') {
            return;
        }

        $method = MollieValue::of($payment->method);
        $method = $method !== '' ? $method : null;

        // The payment id travels with the mandate, because under this provider the payment IS how the
        // mandate was granted — and it is the only thing that says which request this answers. A customer
        // adding a second card establishes a mandate too; without the reference the two are the same event.
        yield new MandateEstablished(
            $customer,
            trim($mandateId),
            'mollie',
            $method,
            MollieValue::id($payment->id),
            $this->cardOf($customer, trim($mandateId), $method),
        );
    }

    /**
     * The card behind a card mandate, read from the mandate itself: the payment does not carry the card's expiry.
     *
     * Only for a card mandate, so a direct debit costs no request. The SDK maps the first payment's method onto
     * the mandate it established, the mapping the payment rails use as well. A mandate Mollie no longer knows
     * yields no card; any other failure travels, as the payment's own does, so the ping is delivered again.
     */
    private function cardOf(string $customer, string $mandateId, ?string $method): ?PaymentMethod
    {
        if ($method === null || MandateMethod::getForFirstPaymentMethod($method) !== self::CARD_MANDATE) {
            return null;
        }

        try {
            $mandate = MollieValue::narrow($this->client->send(new GetMandateRequest($customer, $mandateId)), Mandate::class);
        } catch (RequestException $refusal) {
            if (! $this->saysItDoesNotExist($refusal)) {
                throw $refusal;
            }

            return null;
        }

        $details = is_object($mandate?->details) ? get_object_vars($mandate->details) : [];
        $text = static fn (string $field): ?string => is_string($details[$field] ?? null) && $details[$field] !== '' ? $details[$field] : null;
        $expiry = preg_match('/^(\d{4})-(\d{2})-\d{2}$/', $text('cardExpiryDate') ?? '', $parts) === 1 ? $parts : null;

        return new PaymentMethod(
            id: $mandateId,
            type: self::CARD_MANDATE,
            brand: $text('cardLabel'),
            last4: $text('cardNumber'),
            expMonth: $expiry === null ? null : (int) $expiry[2],
            expYear: $expiry === null ? null : (int) $expiry[1],
        );
    }

    /**
     * The add-on this payment bought, where it bought one.
     *
     * {@see MollieOneTimeCharge} writes the add-on key onto the payment, and a confirmed payment that carries it is a
     * purchase: the credit, the grant and the invoice all follow from this event. A payment without the key, or one
     * whose customer is unknown, bought no add-on this package sold, and yields nothing.
     *
     * The payment id is both references. A refund names the same payment, which is how the purchase is reversed, and
     * it is also what the purchase's order was written under.
     *
     * @return iterable<AddonPurchased>
     */
    private function addonPurchaseOf(Payment $payment, string $customer, Money $amount): iterable
    {
        $metadata = $payment->metadata;
        $key = is_object($metadata) ? ($metadata->{MollieOneTimeCharge::ADDON_KEY} ?? null) : null;

        if (! is_string($key) || $key === '' || $customer === '') {
            return;
        }

        yield new AddonPurchased(
            $customer,
            $key,
            $amount,
            MollieValue::id($payment->id),
            paymentReference: MollieValue::id($payment->id),
            declarationReference: $this->stringOf($metadata, 'withdrawal_declaration'),
            provider: 'mollie',
            callerReference: $this->stringOf($metadata, 'caller_reference'),
        );
    }

    /** A string out of the payment's metadata, or null where it is absent or empty. */
    private function stringOf(object $metadata, string $key): ?string
    {
        $value = $metadata->{$key} ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The refund this payment carries, if anything was taken back from it.
     *
     * Mollie's webhook never names a refund — it pings with the PAYMENT id, and the refund is something the
     * fetched payment turns out to hold. Same shape as the chargeback below, and the same reason.
     *
     * The amount is the provider's CUMULATIVE refunded total rather than this delivery's delta, because
     * that is what {@see AddonRefunded} is defined to carry: the ledger claws back only the part it has not
     * already reversed, so a redelivery and a second partial refund each land correctly without this mapper
     * remembering anything. Sending the payment's own amount instead would reverse the whole purchase for a
     * partial refund, and the customer would lose access they still paid for.
     *
     * A chargeback that still stands is money gone back as well, so it is part of the same figure. The
     * reversal of the purchase and the credit note both count against what they have already taken back, and
     * a second figure for the same payment would read to each of them as the whole of it. Where a chargeback
     * is in the figure, the event says so with the reason a lost dispute carries on every driver.
     *
     * An unreadable refunded amount yields nothing rather than a zero, and a warning that names the payment and
     * what Mollie sent. A refund reported as zero reverses nothing and looks like it worked; one dropped without
     * a word is the same failure, so the log is where it can be found later. An ABSENT one is a payment Mollie
     * offers no refund on, which has refunded nothing.
     *
     * @param  iterable<mixed>  $chargebacks
     * @return iterable<AddonRefunded>
     */
    private function refundOf(Payment $payment, Money $amount, iterable $chargebacks): iterable
    {
        try {
            $refunded = MollieAmount::fromOptionalResource($payment->amountRefunded, $amount->currency);
        } catch (Throwable) {
            Log::warning('billing: Mollie reported a refunded amount this package cannot read, so nothing was reversed', [
                'payment' => MollieValue::id($payment->id),
                'amount' => $payment->amountRefunded,
            ]);

            return;
        }

        $chargedBack = MollieChargebackEvents::standing($chargebacks, $refunded->currency);
        $cumulative = $refunded->plus($chargedBack);

        if (! $cumulative->isPositive()) {
            return;
        }

        yield new AddonRefunded(
            MollieValue::id($payment->id),
            $cumulative,
            reason: $chargedBack->isPositive() ? ReversalCause::DisputeLost->value : null,
        );
    }

    /**
     * The chargebacks this payment turned out to carry.
     *
     * Mollie's legacy webhook never names a chargeback — it pings with the PAYMENT id, and the chargeback
     * is something the fetched payment turns out to have. So it is noticed here and then ASKED for,
     * because the one number that matters is not on the payment.
     *
     * Each chargeback becomes its own event with its own amount, and both halves of that matter. A
     * chargeback is not necessarily the whole payment — partial ones exist, and reporting the payment's
     * amount would claim money back that was never taken. And a payment can carry more than one, so a
     * single event per payment would lose the second entirely: the one somebody finds months later in a
     * reconciliation.
     *
     * The payment's own success is still reported alongside. Both are true and both matter — emitting only
     * the chargeback would leave the order unbooked, emitting only the payment would hide the reversal.
     *
     * @return iterable<mixed>
     */
    private function chargebacksOf(Payment $payment): iterable
    {
        if (! $payment->hasChargebacks()) {
            return [];
        }

        try {
            return $payment->chargebacks();
        } catch (RequestException $refusal) {
            // A follow-up that failed for a reason that says nothing about the chargebacks travels. Answered
            // with nothing, the receiver marked the delivery handled with a 200 and Mollie never delivered it
            // again, so the chargeback waited for an unrelated later ping, if one came. Thrown, the receiver
            // answers 500 and Mollie delivers the ping again; no amount is ever invented in between.
            if (! $this->saysItDoesNotExist($refusal)) {
                throw $refusal;
            }

            return [];
        }
    }

    /**
     * Ask Mollie what happened, answering null where the payment does not exist.
     *
     * That refusal is not an error to escalate: an id that does not resolve is what a forged ping looks
     * like, and also what a redelivery of a payment somebody deleted in test mode looks like. Both want
     * the same outcome — nothing recorded, nothing changed — and letting the exception through would turn
     * a stranger's request into a 500 in our own error tracker.
     *
     * Every other failure travels. A rate limit, an outage or a timeout says nothing about the payment, and
     * answered with null the receiver marked the delivery handled with a 200: Mollie never asked again, and the
     * paid cycle, the failed one or the first mandate the ping announced was lost without a line anywhere.
     * Thrown, the receiver answers 500 and Mollie delivers the ping again.
     */
    private function fetch(string $id): ?Payment
    {
        if (array_key_exists($id, $this->fetched)) {
            return $this->fetched[$id];
        }

        try {
            $payment = $this->client->send(new GetPaymentRequest($id));
        } catch (RequestException $refusal) {
            if (! $this->saysItDoesNotExist($refusal)) {
                throw $refusal;
            }

            return $this->fetched[$id] = null;
        }

        return $this->fetched[$id] = MollieValue::narrow($payment, Payment::class);
    }

    /** Whether Mollie answered that the resource does not exist, the one refusal that is itself an answer. */
    private function saysItDoesNotExist(RequestException $refusal): bool
    {
        return in_array($refusal->getStatusCode(), [404, 410], true);
    }
}
