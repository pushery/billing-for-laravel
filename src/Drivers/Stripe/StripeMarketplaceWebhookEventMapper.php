<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Pushery\Billing\Contracts\MarketplaceWebhookEventMapper;
use Pushery\Billing\Contracts\MerchantAccountDirectory;
use Pushery\Billing\Enums\DisputeReason;
use Pushery\Billing\Enums\ReversalCause;
use Pushery\Billing\Events\BillingDomainEvent;
use Pushery\Billing\Events\ChargebackReceived;
use Pushery\Billing\Events\MerchantAccountDeauthorized;
use Pushery\Billing\Events\MerchantAccountUpdated;
use Pushery\Billing\Events\SubscriptionStateChanged;
use Pushery\Billing\ValueObjects\MerchantAccountReference;
use Pushery\Billing\ValueObjects\MerchantScope;
use Pushery\Billing\ValueObjects\Money;

/**
 * Turns the provider's connected-account events — account lifecycle AND per-merchant subscriptions — into
 * neutral ones, and only ever runs behind the merchant endpoint.
 *
 * It is a separate class rather than more branches on the shipped mapper because those branches would change
 * what EXISTING installations do. A single-merchant app already receives `account.updated` and its own
 * subscription events today; they fall through to nothing on the platform mapper and are ignored. Recognizing
 * them there would make every such app start emitting domain events and running effects on the next deploy,
 * with no config change and nothing announcing it.
 *
 * A connected-account `customer.subscription.*` is a creator's own subscription. It is resolved to the local
 * merchant that owns the firing account, and mapped to the neutral SubscriptionStateChanged carrying BOTH the
 * merchant scope (which keys the local per-merchant row) and the account reference (which scopes the owner
 * lookup). An account with no local merchant on file cannot be attributed, so nothing is emitted.
 *
 * Capabilities are read as strict booleans. A missing field means the provider did not say, and "did not
 * say" must land as false — the flags exist to answer whether money may be routed, and an absent answer is
 * not a yes.
 */
final readonly class StripeMarketplaceWebhookEventMapper implements MarketplaceWebhookEventMapper
{
    public function __construct(
        private StripeSubscriptionMapper $subscriptions,
        private MerchantAccountDirectory $accounts,
    ) {}

    public function map(Request $request): iterable
    {
        $decoded = json_decode($request->getContent(), true);
        $payload = is_array($decoded) ? $decoded : [];

        $type = $payload['type'] ?? null;
        $data = $payload['data'] ?? null;
        $object = is_array($data) ? ($data['object'] ?? null) : null;

        if (! is_string($type) || ! is_array($object)) {
            return;
        }

        // The account the event is ABOUT. On a connected-account delivery the provider names it at the top
        // level; the object's own id is the same account for `account.*`, and the top level is the field
        // that stays correct for every other event type this endpoint may carry later.
        $account = $payload['account'] ?? ($object['id'] ?? null);

        if (! is_string($account) || $account === '') {
            return;
        }

        yield from match ($type) {
            'account.updated' => [$this->accountUpdated($object, $account)],
            'account.application.deauthorized' => [new MerchantAccountDeauthorized('stripe', $account)],
            'customer.subscription.created',
            'customer.subscription.updated',
            'customer.subscription.deleted' => $this->subscriptionEvents($object, $this->strictAccount($payload), $this->int($payload, 'created')),
            'charge.dispute.closed' => $this->disputeClosedEvents($object, $account),
            default => [],
        };
    }

    /**
     * @param  array<array-key, mixed>  $object
     */
    private function accountUpdated(array $object, string $account): MerchantAccountUpdated
    {
        return new MerchantAccountUpdated(new MerchantAccountReference(
            provider: 'stripe',
            accountId: $account,
            chargesEnabled: ($object['charges_enabled'] ?? null) === true,
            payoutsEnabled: ($object['payouts_enabled'] ?? null) === true,
            detailsSubmitted: ($object['details_submitted'] ?? null) === true,
        ));
    }

    /**
     * A connected-account subscription change, resolved to the local merchant that owns the firing account and
     * mapped to the neutral event. A null account (the object-id fallback names the subscription, not the
     * account, for these events) or an account with no local merchant on file yields nothing.
     *
     * @param  array<array-key, mixed>  $object
     * @return list<BillingDomainEvent>
     */
    private function subscriptionEvents(array $object, ?string $account, ?int $occurredAt): array
    {
        if ($account === null) {
            return [];
        }

        $merchant = $this->accounts->merchantForReference($account);

        if (! $merchant instanceof Model) {
            return [];
        }

        $event = $this->subscriptions->toEvent($object, $occurredAt, MerchantScope::forMerchant($merchant), $account);

        return $event instanceof SubscriptionStateChanged ? [$event] : [];
    }

    /**
     * A decided dispute on a merchant's own charge.
     *
     * Only a LOST one moves anything. A won dispute leaves the money where it is, and emitting for it would
     * claw back a merchant's earnings over a case they won — the single most expensive way to be wrong here.
     * An open dispute is not decided at all: the amount that will be corrected is not known until the
     * outcome is, so an event now would correct a base that may never change.
     *
     * The provider's dispute fee is carried as its OWN dimension rather than netted off the amount. It is a
     * service the provider supplied to the platform, not a deduction from what the buyer paid; folded in, it
     * would silently shrink the turnover being corrected by the fee on every disputed sale.
     *
     * An account with no local merchant cannot be attributed to anybody, so nothing is emitted — the same
     * rule the subscription path follows, and for the same reason: a reversal aimed at nobody is worse than
     * one that did not fire.
     *
     * @param  array<array-key, mixed>  $object
     * @return list<BillingDomainEvent>
     */
    private function disputeClosedEvents(array $object, string $account): array
    {
        if (($object['status'] ?? null) !== 'lost') {
            return [];
        }

        $charge = $object['charge'] ?? null;
        $currency = $object['currency'] ?? null;

        if (! is_string($charge) || $charge === '' || ! is_string($currency) || $currency === '') {
            return [];
        }

        $merchant = $this->accounts->merchantForReference($account);

        if (! $merchant instanceof Model) {
            return [];
        }

        $fee = $this->disputeFee($object, strtoupper($currency));

        return [new ChargebackReceived(
            customerReference: is_string($object['payment_intent'] ?? null) ? $object['payment_intent'] : $charge,
            reference: $charge,
            amount: Money::of($this->int($object, 'amount') ?? 0, strtoupper($currency)),
            merchantReference: $account,
            feeAmount: $fee,
            cause: ReversalCause::DisputeLost,
            // Read rather than assumed, and an unknown code is not dropped. Dropping it here was the whole
            // defect: every lost dispute arrived looking alike, so the correction could only ever take one
            // branch, and it took the one that also corrects a merchant who delivered.
            reason: DisputeReason::fromProvider(is_string($object['reason'] ?? null) ? $object['reason'] : null),
            // The dispute's OWN id, which this mapper used to drop. Everything else here is about the
            // charge, correctly — the correcting documents and the clawback act on the sale. The fee does
            // not: it is charged per dispute, and a charge can carry more than one, so claiming it on the
            // charge reference silently lost the second one.
            disputeReference: is_string($object['id'] ?? null) ? $object['id'] : null,
        )];
    }

    /**
     * What the provider charged for handling the dispute, from the balance transactions it reports.
     *
     * THE FIELD IS PLURAL AND IT IS A LIST. This read `$object['balance_transaction']` — singular — and a
     * Dispute has no such key; it declares `balance_transactions`, "a list of zero, one, or two balance
     * transactions that show funds withdrawn and reinstated". So the fee was null on every real webhook,
     * `RecordProviderFee` dropped it at its own guard, and no provider-fee row was ever written for a lost
     * dispute. The suite stayed green because the fixtures wrote the singular key the code read — a payload
     * a test invents can only ever confirm the parse it was written against. StripeDisputePayloadShapeTest
     * now checks the keys against the SDK's own Dispute declaration instead.
     *
     * SUMMED, not first-of-list, and that is what makes the two-entry case right. The withdrawal states the
     * fee as a positive number; a later reinstatement states it back as a negative one. Summing nets those
     * to nothing, which is the truth — taking the magnitude of each and adding them would report a fee that
     * was charged and refunded as charged twice.
     *
     * Read as a MAGNITUDE only at the end: the provider states a fee as a positive number on a negative
     * transaction, and a sign slipping through here would later be added where it should be subtracted.
     *
     * Absent rather than zero when no entry carries one — zero is a claim that nothing was charged, and this
     * cannot know that. A fee that nets to zero IS zero, and is reported as such.
     *
     * @param  array<array-key, mixed>  $object
     */
    private function disputeFee(array $object, string $currency): ?Money
    {
        $transactions = $object['balance_transactions'] ?? null;

        if (! is_array($transactions)) {
            return null;
        }

        $total = null;

        foreach ($transactions as $transaction) {
            $fee = is_array($transaction) ? ($transaction['fee'] ?? null) : null;

            if (is_int($fee)) {
                $total = ($total ?? 0) + $fee;
            }
        }

        return $total === null ? null : Money::of(abs($total), $currency);
    }

    /**
     * The firing account STRICTLY from the top level. A subscription event's object id is the subscription,
     * not the account, so the outer object-id fallback must never reach the subscription path.
     *
     * @param  array<array-key, mixed>  $payload
     */
    private function strictAccount(array $payload): ?string
    {
        $account = $payload['account'] ?? null;

        return is_string($account) && $account !== '' ? $account : null;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function int(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return is_int($value) ? $value : null;
    }
}
