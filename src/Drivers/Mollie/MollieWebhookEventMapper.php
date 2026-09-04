<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Mollie;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Mollie\Api\Http\Requests\GetPaymentRequest;
use Mollie\Api\MollieApiClient;
use Mollie\Api\Resources\Payment;
use Mollie\Api\Types\PaymentStatus;
use Pushery\Billing\Contracts\DerivesDeliveryKey;
use Pushery\Billing\Contracts\WebhookEventMapper;
use Pushery\Billing\Events\AddonRefunded;
use Pushery\Billing\Events\ChargebackReceived;
use Pushery\Billing\Events\MandateEstablished;
use Pushery\Billing\Events\PaymentFailed;
use Pushery\Billing\Events\PaymentSucceeded;
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
    public function __construct(private readonly MollieApiClient $client) {}

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

        $status = trim($payment->status) !== '' ? trim($payment->status) : 'unknown';

        return $id.':'.$status;
    }

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

    /** @return iterable<AddonRefunded|ChargebackReceived|MandateEstablished|PaymentFailed|PaymentSucceeded> */
    public function map(Request $request): iterable
    {
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

        $amount = MollieAmount::fromResource($payment->amount);
        $customer = is_string($payment->customerId) ? $payment->customerId : '';

        if ($payment->isPaid()) {
            yield new PaymentSucceeded($customer, $amount, (string) $payment->id);

            yield from $this->mandateOf($payment, $customer);

            yield from $this->refundOf($payment);

            yield from $this->chargebacksOf($payment, $customer);

            return;
        }

        if ($payment->isFailed() || $payment->isCanceled() || $payment->isExpired()) {
            yield new PaymentFailed($customer, $amount, (string) $payment->id);

            return;
        }

        $this->noteUnmappedStatus($payment);
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
        // Read from the SDK's own type constants rather than typed here, so a status Mollie renames does
        // not leave a hand-copied literal quietly matching nothing.
        $inert = [PaymentStatus::OPEN, PaymentStatus::PENDING, PaymentStatus::AUTHORIZED];

        if (in_array($payment->status, $inert, true)) {
            return;
        }

        Log::warning('billing: unmapped Mollie payment status, so this delivery produced no event', [
            'payment' => (string) $payment->id,
            'status' => $payment->status,
        ]);
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

        $method = is_string($payment->method) && $payment->method !== '' ? $payment->method : null;

        // The payment id travels with the mandate, because under this provider the payment IS how the
        // mandate was granted — and it is the only thing that says which request this answers. A customer
        // adding a second card establishes a mandate too; without the reference the two are the same event.
        yield new MandateEstablished($customer, trim($mandateId), 'mollie', $method, (string) $payment->id);
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
     * An unreadable amount yields nothing rather than a zero. A refund reported as zero reverses nothing
     * and looks like it worked, which is the failure that leaves no trace to find later.
     *
     * @return iterable<AddonRefunded>
     */
    private function refundOf(Payment $payment): iterable
    {
        if ($payment->amountRefunded === null) {
            return;
        }

        try {
            $refunded = MollieAmount::fromResource($payment->amountRefunded);
        } catch (Throwable) {
            return;
        }

        if (! $refunded->isPositive()) {
            return;
        }

        yield new AddonRefunded((string) $payment->id, $refunded);
    }

    /**
     * The chargebacks this payment turned out to carry, one event each.
     *
     * Mollie's legacy webhook never names a chargeback — it pings with the PAYMENT id, and the chargeback
     * is something the fetched payment turns out to have. So it is noticed here and then ASKED for,
     * because the one number that matters is not on the payment.
     *
     * Each chargeback is its own event with its own amount and reference, and both halves of that matter.
     * A chargeback is not necessarily the whole payment — partial ones exist, and reporting the payment's
     * amount would claim money back that was never taken. And a payment can carry more than one, so a
     * single event per payment would lose the second entirely: the one somebody finds months later in a
     * reconciliation.
     *
     * The payment's own success is still reported alongside. Both are true and both matter — emitting only
     * the chargeback would leave the order unbooked, emitting only the payment would hide the reversal.
     *
     * @return iterable<ChargebackReceived>
     */
    private function chargebacksOf(Payment $payment, string $customer): iterable
    {
        if (! $payment->hasChargebacks()) {
            return;
        }

        try {
            $chargebacks = $payment->chargebacks();
        } catch (Throwable) {
            // The payment said it has them and the follow-up failed. Reporting nothing is the safe half of
            // a bad situation: the alternative is inventing an amount, and a chargeback booked at the wrong
            // figure is worse than one booked late — the redelivery will bring it round again.
            return;
        }

        yield from MollieChargebackEvents::from($chargebacks, $customer);
    }

    /**
     * Ask Mollie what happened, answering null when it will not say.
     *
     * A refusal here is not an error to escalate: an id that does not resolve is what a forged ping looks
     * like, and also what a redelivery of a payment somebody deleted in test mode looks like. Both want
     * the same outcome — nothing recorded, nothing changed — and letting the exception through would turn
     * a stranger's request into a 500 in our own error tracker.
     */
    private function fetch(string $id): ?Payment
    {
        if (array_key_exists($id, $this->fetched)) {
            return $this->fetched[$id];
        }

        try {
            $payment = $this->client->send(new GetPaymentRequest($id));
        } catch (Throwable) {
            return $this->fetched[$id] = null;
        }

        return $this->fetched[$id] = $payment instanceof Payment ? $payment : null;
    }
}
