<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Pushery\Billing\Contracts\MerchantAccountDirectory;
use Pushery\Billing\Contracts\WebhookEventMapper;
use Pushery\Billing\Enums\InvoiceStatus;
use Pushery\Billing\Events\AddonPurchased;
use Pushery\Billing\Events\AddonRefunded;
use Pushery\Billing\Events\BillingDomainEvent;
use Pushery\Billing\Events\InvoiceCorrected;
use Pushery\Billing\Events\InvoiceFinalized;
use Pushery\Billing\Events\InvoiceUpcoming;
use Pushery\Billing\Events\MandateRevoked;
use Pushery\Billing\Events\PaymentActionRequired;
use Pushery\Billing\Events\PaymentFailed;
use Pushery\Billing\Events\PaymentSucceeded;
use Pushery\Billing\Events\RoutedChargeAbandoned;
use Pushery\Billing\Events\RoutedChargeConfirmed;
use Pushery\Billing\Events\RoutedSubscriptionInvoicePaid;
use Pushery\Billing\Events\SubscriptionStateChanged;
use Pushery\Billing\Events\TrialEnding;
use Pushery\Billing\ValueObjects\InvoiceCorrectionSnapshot;
use Pushery\Billing\ValueObjects\InvoiceSnapshot;
use Pushery\Billing\ValueObjects\MerchantScope;
use Pushery\Billing\ValueObjects\Money;

/**
 * Translates a verified Stripe webhook into zero or more neutral domain events. This is the one place
 * Stripe's vocabulary (`customer.subscription.updated`, `invoice.payment_failed`, …) is turned into
 * the stable events the effects listen on. The payload is trusted here — the receiver only calls this
 * after the verifier has authenticated the signature — so it decodes the body rather than re-verifying.
 * A price id is resolved back to a tier key through the catalog; an event with no neutral meaning
 * yields nothing.
 *
 * `charge.refunded` maps to AddonRefunded so a refunded one-time add-on's credit is automatically
 * clawed back — the MONEY side. `credit_note.created` maps to InvoiceCorrected — the ACCOUNTING side:
 * the document that credits a finalized invoice, with the lines and tax a raw refund event does not
 * carry. The two are deliberately separate concerns, not a duplicate mapping of the same money.
 *
 * `@partially-mapped:` `charge.dispute.closed` IS answered, and books the dispute — what it does not do is
 * resolve an OWNER from it. The qualifier below carries the whole difference, so it is stated here where a
 * reader looking for the exclusion will find it.
 *
 * Scope notes. A voided credit note (credit_note.voided) is not mapped: reversing an already-booked
 * credit is a distinct accounting action whose direction needs its own decision, and emitting the wrong
 * booking is worse than leaving a rare correction to be made by hand. Dispute and mandate-revocation
 * webhooks (charge.dispute.*, mandate/payment-method events) are not mapped for owner resolution either —
 * the provider dispute and mandate objects carry no customer, so that needs a dedicated design. The
 * neutral ChargebackReceived / MandateRevoked events exist for the driver and consumers that will produce
 * them.
 *
 * `@partially-mapped:` `payment_intent.succeeded`, `payment_intent.payment_failed` and
 * `payment_intent.canceled` ARE answered — but only for a ROUTED marketplace charge, and every one of them
 * returns nothing for a payment that carries an invoice. The sentence below used to say the family was not
 * mapped at all, which stopped being true when the routed lane landed.
 *
 * `payment_intent.*` is mapped for NOTHING INVOICE-DRIVEN, which is every customer-facing payment. Those are
 * already covered: a subscription charge fires `invoice.payment_*` (which carries the invoice reference the
 * dunning notice dedups on), an add-on fires `checkout.session.*`, and an off-session recovery charge
 * returns its ChargeResult to the caller synchronously. A `payment_intent.payment_failed` fires in PARALLEL
 * with `invoice.payment_failed` for the SAME failure but carries the payment-intent id, not the invoice —
 * so mapping THAT to PaymentFailed would bypass the dunning dedup and mail the customer a second "payment
 * failed", while covering no failure the invoice event does not already cover. `PaymentIntentMappingTest`
 * pins the exclusion so it stays a decision rather than an oversight.
 *
 * What the routed arms map instead is a payment with NO invoice at all — the discriminator is taken from the
 * payload rather than from a lookup, and the effect behind it no-ops on a reference this package never wrote
 * as a routed charge. So an ordinary invoice-less checkout emits an event that finds nothing.
 */
final readonly class StripeWebhookEventMapper implements WebhookEventMapper
{
    public function __construct(
        private StripeSubscriptionMapper $subscriptions,
        private MerchantAccountDirectory $accounts,
    ) {}

    public function map(Request $request): iterable
    {
        $payload = json_decode($request->getContent(), true);

        if (! is_array($payload)) {
            return [];
        }

        $data = $payload['data'] ?? null;
        $object = is_array($data) ? ($data['object'] ?? null) : null;

        if (! is_array($object)) {
            return [];
        }

        return match ($this->string($payload, 'type')) {
            'customer.subscription.created',
            'customer.subscription.updated',
            'customer.subscription.deleted' => $this->subscriptionEvents($object, $this->int($payload, 'created')),
            'customer.subscription.trial_will_end' => $this->trialWillEndEvents($object),
            'invoice.upcoming' => $this->upcomingInvoiceEvents($object),
            'invoice.finalized' => $this->invoiceSnapshotEvents($object),
            'invoice.payment_failed' => $this->invoiceEvents($object, failed: true),
            'invoice.payment_action_required' => $this->paymentActionRequiredEvents($object),
            // A paid invoice is both a payment (dunning recovery reads it) and a finalized invoice to
            // persist — with its status now paid. PersistInvoice upserts on (provider, provider_id), so a
            // finalize-then-pay pair converges to one row that ends up paid, whatever order they arrive in.
            'invoice.payment_succeeded' => [
                ...$this->invoiceEvents($object, failed: false),
                ...$this->invoiceSnapshotEvents($object),
                // A THIRD reading of the same message, and deliberately its own event. The two above are
                // about the platform's relationship with the buyer and fire for every payment there is;
                // this one exists so the handler that reaches the provider is only ever woken for a cycle
                // that could plausibly route. See RoutedSubscriptionInvoicePaid.
                ...$this->subscriptionCycleEvents($object),
            ],
            'checkout.session.completed',
            'checkout.session.async_payment_succeeded' => $this->checkoutEvents($object),
            // `@partially-mapped:` payment_intent.succeeded, payment_intent.payment_failed and
            // payment_intent.canceled ARE answered, for routed marketplace charges only. What stays out is
            // every INVOICE-DRIVEN payment, which is the whole customer-facing surface — and that qualifier
            // is the claim, not a softening of it.
            //
            // `payment_intent.*` stays unmapped for anything invoice-driven, which is every customer-facing
            // payment. That pin is not being loosened: it exists because `payment_intent.payment_failed`
            // fires in parallel with `invoice.payment_failed` for the same failure while carrying the
            // payment-intent id rather than the invoice, so mapping it would bypass a dunning dedup keyed on
            // the invoice and mail the customer a second failure notice.
            //
            // A ROUTED marketplace charge is not that. It is not invoiced through the provider at all, so
            // the payload carries no `invoice` — and that absence is the discriminator, taken from the
            // payload itself rather than from a database lookup a mapper has no business doing. Every case
            // the pin covers carries an invoice and still maps to nothing.
            //
            // The rest of the narrowing happens downstream: the effect only acts on a reference matching a
            // routed charge this package wrote as pending, and no-ops otherwise. So an ordinary one-time
            // checkout, which also has no invoice, emits an event that finds nothing and changes nothing.
            'payment_intent.succeeded' => $this->routedChargeEvents($object, confirmed: true),
            'payment_intent.payment_failed',
            'payment_intent.canceled' => $this->routedChargeEvents($object, confirmed: false),
            'charge.refunded' => $this->refundEvents($object),
            'charge.dispute.closed' => $this->disputeClosedEvents($object),
            'credit_note.created' => $this->correctionEvents($object),
            'payment_method.detached' => $this->paymentMethodDetachedEvents($object, $data),
            default => [],
        };
    }

    /**
     * A detached payment method — a card removed, a mandate-backed method taken off the customer. After the
     * detach the object's `customer` is null, but Stripe carries the customer it WAS attached to in the
     * event's `previous_attributes`, so the owner to notify is resolvable without a local mandate store
     * (which is exactly what left this unmapped before). Maps to the neutral MandateRevoked.
     *
     * Note: this covers a payment method REMOVED from the customer. A SEPA mandate that goes inactive while
     * the method stays attached (`mandate.updated`) carries no customer and is not mapped here — its charge
     * failure is still caught reactively by the dunning path.
     *
     * @param  array<array-key, mixed>  $object
     * @param  array<array-key, mixed>  $data
     * @return list<BillingDomainEvent>
     */
    private function paymentMethodDetachedEvents(array $object, array $data): array
    {
        $customer = $this->string($object, 'customer') ?? $this->previousCustomer($data);
        $id = $this->string($object, 'id');

        if ($customer === null || $id === null) {
            return [];
        }

        return [new MandateRevoked($customer, $id)];
    }

    /**
     * The customer an event's object was attached to before the change, from `previous_attributes`.
     *
     * @param  array<array-key, mixed>  $data
     */
    private function previousCustomer(array $data): ?string
    {
        $previous = $data['previous_attributes'] ?? null;

        return is_array($previous) ? $this->string($previous, 'customer') : null;
    }

    /**
     * A refunded charge. Stripe reports a CUMULATIVE `amount_refunded`, keyed on the PaymentIntent — the
     * same id a one-time add-on stored when it was bought, so a reversal is matched to the purchase it
     * undoes. A refund matching no tracked add-on reverses nothing.
     *
     * @param  array<array-key, mixed>  $object
     * @return list<BillingDomainEvent>
     */
    private function refundEvents(array $object): array
    {
        $paymentReference = $this->string($object, 'payment_intent');
        $currency = $this->string($object, 'currency');

        if ($paymentReference === null || $currency === null) {
            return [];
        }

        return [new AddonRefunded(
            $paymentReference,
            Money::of($this->int($object, 'amount_refunded') ?? 0, strtoupper($currency)),
        )];
    }

    /**
     * A closed dispute. Only a LOST dispute claws anything back — the money is gone the same as a refund,
     * so it maps to the same add-on reversal, keyed on the dispute's PaymentIntent. A won or otherwise
     * closed dispute reverses nothing: mapping it unconditionally would strip a customer of credit for a
     * dispute the merchant actually won. The dispute object carries no customer, which is exactly why the
     * reversal resolves the owner from the purchase row instead of a customer reference.
     *
     * @param  array<array-key, mixed>  $object
     * @return list<BillingDomainEvent>
     */
    private function disputeClosedEvents(array $object): array
    {
        if ($this->string($object, 'status') !== 'lost') {
            return [];
        }

        $paymentReference = $this->string($object, 'payment_intent');
        $currency = $this->string($object, 'currency');

        if ($paymentReference === null || $currency === null) {
            return [];
        }

        return [new AddonRefunded(
            $paymentReference,
            Money::of($this->int($object, 'amount') ?? 0, strtoupper($currency)),
            reason: 'dispute_lost',
        )];
    }

    /**
     * @param  array<array-key, mixed>  $object
     * @return list<BillingDomainEvent>
     */
    private function subscriptionEvents(array $object, ?int $occurredAt): array
    {
        // A routed subscription rides a destination charge: it lives on the PLATFORM account (so its event
        // arrives here, not on the merchant endpoint) but carries transfer_data.destination naming the
        // connected account its money is owed to. That destination resolves to the local merchant, which
        // keys the fan's per-creator row — while the customer stays a platform customer, so the owner is
        // still resolved globally (no account reference rides along). A subscription with no transfer_data is
        // a plain platform sale and maps exactly as before.
        $event = $this->subscriptions->toEvent($object, $occurredAt, $this->routedMerchant($object));

        return $event instanceof SubscriptionStateChanged ? [$event] : [];
    }

    /**
     * The merchant a subscription's destination charge routes to, from transfer_data.destination, or null for
     * an unrouted platform subscription. The destination is the connected account id (a string, or an
     * expanded account object); an account with no local merchant on file resolves to null rather than a
     * guess.
     *
     * @param  array<array-key, mixed>  $object
     */
    private function routedMerchant(array $object): ?MerchantScope
    {
        $transfer = $object['transfer_data'] ?? null;
        $destination = is_array($transfer) ? ($transfer['destination'] ?? null) : null;

        // Expanded, the destination is an account object; unexpanded (the webhook default) it is the id.
        $accountId = is_array($destination) ? $this->string($destination, 'id') : (is_string($destination) ? $destination : null);

        if ($accountId === null || $accountId === '') {
            return null;
        }

        $merchant = $this->accounts->merchantForReference($accountId);

        return $merchant instanceof Model ? MerchantScope::forMerchant($merchant) : null;
    }

    /**
     * The bank held this invoice's payment for authentication (3-D Secure). Keyed on the invoice id, which
     * is the dedup reference the action-required notice commits, so a redelivery does not nag the customer.
     *
     * @param  array<array-key, mixed>  $object
     * @return list<BillingDomainEvent>
     */
    private function paymentActionRequiredEvents(array $object): array
    {
        $customer = $this->string($object, 'customer');
        $id = $this->string($object, 'id');

        if ($customer === null || $id === null) {
            return [];
        }

        return [new PaymentActionRequired($customer, $id)];
    }

    /**
     * @param  array<array-key, mixed>  $object
     * @return list<BillingDomainEvent>
     */
    private function invoiceEvents(array $object, bool $failed): array
    {
        $customer = $this->string($object, 'customer');
        $id = $this->string($object, 'id');
        $currency = $this->string($object, 'currency');

        if ($customer === null || $id === null || $currency === null) {
            return [];
        }

        $amount = Money::of(
            $this->int($object, $failed ? 'amount_due' : 'amount_paid') ?? 0,
            strtoupper($currency),
        );

        return [$failed
            ? new PaymentFailed($customer, $amount, $id)
            : new PaymentSucceeded($customer, $amount, $id),
        ];
    }

    /**
     * The cycle behind a paid invoice, when there is one.
     *
     * Emitted for EVERY paid subscription invoice, routed or not — because the delivered payload cannot say
     * which. It carries no `transfer_data` and no account (measured 2026-08-07 on the pinned version), so
     * the discrimination has to happen where this package already knows the answer: on the local
     * subscription row. Deciding here would mean fetching from the provider inside a mapper, which is the
     * one thing a mapper must never do.
     *
     * A one-off invoice with no subscription yields nothing. There is no cycle, so there is no rate, and
     * the routed one-time lane records its own row at checkout anyway.
     *
     * @param  array<array-key, mixed>  $object
     * @return list<BillingDomainEvent>
     */
    private function subscriptionCycleEvents(array $object): array
    {
        $customer = $this->string($object, 'customer');
        $invoice = $this->string($object, 'id');
        $subscription = $this->string($object, 'subscription');

        if ($customer === null || $invoice === null || $subscription === null) {
            return [];
        }

        return [new RoutedSubscriptionInvoicePaid($customer, $invoice, $subscription)];
    }

    /**
     * A subscription's free trial is about to end (Stripe sends this a few days out). Maps to the neutral
     * TrialEnding so the reminder goes out before the first charge. Needs the customer (to notify), the
     * subscription id (to dedup the reminder once per trial end) and the trial-end timestamp; without all
     * three there is nothing actionable to send.
     *
     * @param  array<array-key, mixed>  $object
     * @return list<BillingDomainEvent>
     */
    private function trialWillEndEvents(array $object): array
    {
        $customer = $this->string($object, 'customer');
        $subscription = $this->string($object, 'id');
        $trialEnd = $this->int($object, 'trial_end');

        if ($customer === null || $subscription === null || $trialEnd === null) {
            return [];
        }

        return [new TrialEnding($customer, $subscription, Carbon::createFromTimestamp($trialEnd, 'UTC'))];
    }

    /**
     * A customer's next invoice is about to be finalized. Maps to the neutral InvoiceUpcoming so the
     * force-flush effect can drain that customer's usage outbox onto the invoice before it closes.
     *
     * The upcoming invoice is a preview and carries no id of its own; the one field that matters is the
     * customer, and without it there is nobody to flush.
     *
     * @param  array<array-key, mixed>  $object
     * @return list<BillingDomainEvent>
     */
    private function upcomingInvoiceEvents(array $object): array
    {
        $customer = $this->string($object, 'customer');

        return $customer === null ? [] : [new InvoiceUpcoming($customer)];
    }

    /**
     * @param  array<array-key, mixed>  $object
     * @return list<BillingDomainEvent>
     */
    private function checkoutEvents(array $object): array
    {
        if ($this->string($object, 'mode') !== 'payment') {
            return [];
        }

        // Only credit a settled payment. An async method (SEPA debit, boleto, …) fires
        // checkout.session.completed while still 'unpaid'; the credit waits for the later
        // async_payment_succeeded, which re-enters here with payment_status 'paid'.
        if (! in_array($this->string($object, 'payment_status'), ['paid', 'no_payment_required'], true)) {
            return [];
        }

        $metadata = $object['metadata'] ?? null;
        $addonKey = is_array($metadata) ? $this->string($metadata, 'addon_key') : null;

        $customer = $this->string($object, 'customer');
        $id = $this->string($object, 'id');
        $currency = $this->string($object, 'currency');

        if ($addonKey === null || $customer === null || $id === null || $currency === null) {
            return [];
        }

        return [new AddonPurchased(
            $customer,
            $addonKey,
            Money::of($this->int($object, 'amount_total') ?? 0, strtoupper($currency)),
            $id,
            // The PaymentIntent — the reversal key a later refund webhook carries (the session id it does not).
            $this->string($object, 'payment_intent'),
            // THE ROUND TRIP, and the reason the declarations can be found again at all. The package mints
            // this key before the redirect and writes the two declarations against it; the provider carries
            // it as opaque metadata and hands it back here. Read from the same metadata bag as the add-on
            // key, so a session that lost one lost both -- which is far easier to notice than a payment that
            // quietly arrives with no declaration attached.
            is_array($metadata) ? $this->string($metadata, 'withdrawal_declaration') : null,
        )];
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    /**
     * Build the neutral finalized-invoice snapshot from a Stripe invoice object, ready to persist and to
     * render an EN 16931 document from without going back to Stripe.
     *
     * @param  array<array-key, mixed>  $object
     * @return list<BillingDomainEvent>
     */
    private function invoiceSnapshotEvents(array $object): array
    {
        $customer = $this->string($object, 'customer');
        $id = $this->string($object, 'id');
        $currency = $this->string($object, 'currency');

        if ($customer === null || $id === null || $currency === null) {
            return [];
        }

        $total = $this->int($object, 'total') ?? 0;
        [$net, $tax] = $this->netAndTax($object, $total);

        return [new InvoiceFinalized(new InvoiceSnapshot(
            provider: 'stripe',
            providerId: $id,
            customerReference: $customer,
            number: $this->string($object, 'number'),
            currency: strtoupper($currency),
            status: $this->invoiceStatus($object),
            totalMinor: $total,
            subtotalMinor: $net,
            taxMinor: $tax,
            issuedAt: $this->finalizedAt($object),
            buyer: $this->buyerSnapshot($object),
            lines: $this->lineSnapshots($object, $net, $tax),
            // Stripe stamps the customer's tax status onto the finalized invoice: 'reverse' is an intra-EU
            // B2B reverse charge (the buyer accounts for the VAT). That is the fact the e-invoice must carry.
            reverseCharge: $this->string($object, 'customer_tax_exempt') === 'reverse',
        ))];
    }

    /**
     * The invoice's net (taxable base) and tax, both AFTER any discount. Stripe's `subtotal` is the
     * pre-discount, pre-tax amount, so subtracting an invoice-level discount (promotion codes are allowed by
     * default) gives the real taxable base; the tax is then total - net. Deriving tax as total - subtotal
     * would be wrong by exactly the discount — it would report tax that was never charged and a net that the
     * lines do not sum to, producing a legally invalid EN 16931 e-invoice. Net is not floored: a downgrade
     * can finalize a genuinely negative invoice, which must persist faithfully. The tax floor keeps a rounding
     * wobble from ever emitting a negative VAT.
     *
     * @param  array<array-key, mixed>  $object
     * @return array{0: int, 1: int}
     */
    private function netAndTax(array $object, int $total): array
    {
        $grossSubtotal = $this->int($object, 'subtotal') ?? $total;
        $net = $grossSubtotal - $this->sumAmounts($object['total_discount_amounts'] ?? null);

        return [$net, max(0, $total - $net)];
    }

    /**
     * Sum the `amount` fields of a Stripe amount list (total_discount_amounts, a line's discount_amounts).
     */
    private function sumAmounts(mixed $rows): int
    {
        $sum = 0;

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row)) {
                $sum += $this->int($row, 'amount') ?? 0;
            }
        }

        return $sum;
    }

    /**
     * Build the neutral credit-note snapshot from a Stripe credit note object. The amounts are the credit
     * note's own positive magnitudes; the net and tax are derived after any discount, exactly as for the
     * invoice. The `invoice` field is the id of the invoice being credited — the reference the effect
     * resolves the original row and its number from. The buyer is left to the effect, which takes it from
     * the frozen original invoice (a Stripe credit note references its customer but does not re-embed the
     * name and address the invoice froze).
     *
     * @param  array<array-key, mixed>  $object
     * @return list<BillingDomainEvent>
     */
    private function correctionEvents(array $object): array
    {
        $customer = $this->string($object, 'customer');
        $id = $this->string($object, 'id');
        $currency = $this->string($object, 'currency');
        $creditedInvoice = $this->string($object, 'invoice');

        if ($customer === null || $id === null || $currency === null || $creditedInvoice === null) {
            return [];
        }

        $total = $this->int($object, 'total') ?? 0;
        [$net, $tax] = $this->netAndTax($object, $total);

        return [new InvoiceCorrected(new InvoiceCorrectionSnapshot(
            provider: 'stripe',
            providerId: $id,
            customerReference: $customer,
            number: $this->string($object, 'number'),
            currency: strtoupper($currency),
            totalMinor: $total,
            subtotalMinor: $net,
            taxMinor: $tax,
            issuedAt: $this->int($object, 'created'),
            creditsProviderId: $creditedInvoice,
            buyer: $this->buyerSnapshot($object),
            lines: $this->lineSnapshots($object, $net, $tax),
            reverseCharge: $this->string($object, 'customer_tax_exempt') === 'reverse',
        ))];
    }

    /**
     * The neutral invoice status for a Stripe invoice object. Unknown statuses read as open.
     *
     * @param  array<array-key, mixed>  $object
     */
    private function invoiceStatus(array $object): InvoiceStatus
    {
        return match ($this->string($object, 'status')) {
            'paid' => InvoiceStatus::Paid,
            'void' => InvoiceStatus::Void,
            'uncollectible' => InvoiceStatus::Uncollectible,
            'draft' => InvoiceStatus::Draft,
            default => InvoiceStatus::Open,
        };
    }

    /**
     * When the invoice was finalized (its number assigned), falling back to created, then null.
     *
     * @param  array<array-key, mixed>  $object
     */
    private function finalizedAt(array $object): ?int
    {
        $transitions = $object['status_transitions'] ?? null;
        $finalized = is_array($transitions) ? $this->int($transitions, 'finalized_at') : null;

        return $finalized ?? $this->int($object, 'created');
    }

    /**
     * The buyer party, frozen from the invoice's customer snapshot — Stripe copies the customer's name and
     * address onto the invoice at finalization, which is exactly the immutable buyer §14 UStG requires.
     *
     * @param  array<array-key, mixed>  $object
     * @return array<string, string>
     */
    private function buyerSnapshot(array $object): array
    {
        $address = $object['customer_address'] ?? null;
        $address = is_array($address) ? $address : [];

        return array_filter([
            'name' => $this->string($object, 'customer_name') ?? '',
            'address' => trim(($this->string($address, 'line1') ?? '').' '.($this->string($address, 'line2') ?? '')),
            'postcode' => $this->string($address, 'postal_code') ?? '',
            'city' => $this->string($address, 'city') ?? '',
            'country' => $this->string($address, 'country') ?? '',
            'vat_id' => $this->firstTaxId($object),
            // The buyer's email is the truest EN 16931 electronic address (BT-49, EAS "EM") — captured so a
            // conformant XRechnung can route to it instead of falling back to the VAT id as an address.
            'email' => $this->string($object, 'customer_email') ?? '',
        ], static fn (string $value): bool => $value !== '');
    }

    /**
     * The customer's first tax id (VAT id) on the invoice, or an empty string.
     *
     * @param  array<array-key, mixed>  $object
     */
    private function firstTaxId(array $object): string
    {
        $ids = $object['customer_tax_ids'] ?? null;
        $first = is_array($ids) ? ($ids[0] ?? null) : null;

        return is_array($first) ? ($this->string($first, 'value') ?? '') : '';
    }

    /**
     * The invoice lines, each carrying its net AFTER any discount so the lines sum to the document net (the
     * discount-adjusted taxable base). Stripe's line `amount` is the pre-discount figure and its per-line
     * `discount_amounts` carry the reduction, so the post-discount net is amount minus those. Stripe's
     * per-line tax rate is not reliably present in a webhook payload (the rate object is not expanded), so
     * each line carries the invoice's EFFECTIVE rate — exact for the common single-rate invoice, and
     * consistent with the stored net and tax. A lineless invoice gets one summary line, because an EN 16931
     * invoice must have at least one (BR-16).
     *
     * @param  array<array-key, mixed>  $object
     * @return list<array{description: string, quantity: int, unit: string, unit_price_minor: int, net_minor: int, tax_rate: float}>
     */
    private function lineSnapshots(array $object, int $net, int $tax): array
    {
        $rate = $net > 0 ? round($tax / $net * 100, 1) : 0.0;

        $data = $object['lines'] ?? null;
        $rows = is_array($data) ? ($data['data'] ?? null) : null;

        $lines = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $lineNet = ($this->int($row, 'amount') ?? 0) - $this->sumAmounts($row['discount_amounts'] ?? null);
            $quantity = $this->int($row, 'quantity') ?? 1;

            $lines[] = [
                'description' => $this->string($row, 'description') ?? 'Item',
                'quantity' => $quantity,
                'unit' => 'C62',
                'unit_price_minor' => $quantity !== 0 ? intdiv($lineNet, $quantity) : $lineNet,
                'net_minor' => $lineNet,
                'tax_rate' => $rate,
            ];
        }

        if ($lines === []) {
            $lines[] = [
                'description' => 'Invoice',
                'quantity' => 1,
                'unit' => 'C62',
                'unit_price_minor' => $net,
                'net_minor' => $net,
                'tax_rate' => $rate,
            ];
        }

        return $lines;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function string(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function int(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return is_int($value) ? $value : null;
    }

    /**
     * A routed marketplace charge clearing, or not, after the fact.
     *
     * @param  array<array-key, mixed>  $object
     * @return list<BillingDomainEvent>
     */
    private function routedChargeEvents(array $object, bool $confirmed): array
    {
        // Invoice-driven means customer-facing, and customer-facing is what the pin protects. Checked for
        // PRESENCE rather than truthiness: the provider sends `invoice: null` on a payment that has none,
        // and a null-check that treated that as "has an invoice" would map nothing, forever, silently.
        if (($object['invoice'] ?? null) !== null) {
            return [];
        }

        $reference = $object['id'] ?? null;

        if (! is_string($reference) || $reference === '') {
            return [];
        }

        // THE TRANSFER THE PROVIDER ALREADY MADE, carried through instead of dropped.
        //
        // On a destination charge the transfer is created as the payment settles, and the payload names it.
        // The synchronous path has always passed that reference on; this one settled the charge WITHOUT it,
        // so a hosted checkout produced a settled row whose link to the provider's transfer never existed —
        // and that link is the key any reconciliation against the provider has to join on.
        //
        // Read as a string only. Stripe expands this field to an object when asked to, and an expanded
        // transfer is not an id: storing `Array` or a nested payload here would be worse than the null it
        // replaced, because null at least says nothing.
        $transfer = $object['transfer'] ?? null;

        return [$confirmed
            ? new RoutedChargeConfirmed('stripe', $reference, is_string($transfer) && $transfer !== '' ? $transfer : null)
            : new RoutedChargeAbandoned('stripe', $reference)];
    }
}
