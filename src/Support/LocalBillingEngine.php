<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Pushery\Billing\Contracts\BillingEngine;
use Pushery\Billing\Contracts\PaymentRails;
use Pushery\Billing\Discounts\CycleCouponApplier;
use Pushery\Billing\Dunning\ConfigDunningLadder;
use Pushery\Billing\Enums\CreditReason;
use Pushery\Billing\Enums\OrderItemType;
use Pushery\Billing\Enums\OrderStatus;
use Pushery\Billing\Enums\SubscriptionState;
use Pushery\Billing\Events\PaymentFailed;
use Pushery\Billing\Events\PaymentSucceeded;
use Pushery\Billing\Invoicing\OrderInvoiceIssuer;
use Pushery\Billing\Models\Order;
use Pushery\Billing\Models\OrderItem;
use Pushery\Billing\Models\PaymentMandate;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\ValueObjects\ChargeNarrative;
use Pushery\Billing\ValueObjects\CreditSource;
use Pushery\Billing\ValueObjects\DriverCapabilities;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\OrderItemDraft;
use Throwable;

/**
 * The billing cycle for a driver whose provider does not run one.
 *
 * Stripe is told when to charge and charges itself, so its engine's `tick()` is a deliberate no-op. This
 * one decides on its own initiative, which changes what can go wrong — and the three things that can are
 * what most of this class is about:
 *
 * **The same cycle assembled twice.** A scheduler that overlaps, a retried queue job, an operator running
 * the command by hand: all ordinary, all a second debit if the cycle is not claimed exactly once. The claim
 * is the unique `(subscription_id, period_start)` on the orders table — a database constraint rather than a
 * check, because a check has a window and a constraint does not.
 *
 * **A charge inside a transaction.** The charge is a network call to a provider, and a call held inside an
 * open transaction keeps a row lock for as long as the provider takes to answer — which, on the bad day
 * this matters, is a timeout. So the order is committed first, the charge happens outside, and the result
 * is written afterwards. The cost is a visible intermediate state (an order that is `processing` while the
 * provider decides), which is the honest description of what is actually happening.
 *
 * **One subscriber taking down the run.** A loop that stops at the first exception bills whoever sorts
 * first and silently skips everybody after them, with the failure in the logs rather than in anyone's
 * inbox. Each subscription is therefore isolated, and a failure moves to the next.
 *
 * **How the next driver gets this cycle — the extension point, and it is not inheritance.**
 *
 * Construct it. `new LocalBillingEngine('adyen', $adyenRails, $credit, $config)` is the whole of what a
 * second driver does; there is no method to implement and no hook to fill. Everything provider-shaped —
 * how to charge, what a mandate is, what a result means — already crosses the `PaymentRails` seam, so a
 * driver supplies rails and a name and inherits the cycle by using it.
 *
 * The milestone asked for an abstract base class the second driver extends. An abstract class earns its
 * keep when subclasses must supply something, and here they must not: it would have no abstract member,
 * which is a base class in name only. It would also cost something real — a subclass can override any
 * step, so "Adyen runs what Mollie runs" quietly degrades into "Adyen runs something similar", which is
 * the outcome the shared core exists to prevent. The milestone's own criterion allows this reading: the
 * provider-specific parts are to be extracted as abstract methods **or injected collaborators**.
 *
 * Two things hold that claim rather than stating it. `LocalEngineIsReusableByASecondDriverTest` drives the
 * full cycle — including the dunning half, not just the happy path — for a provider that exists nowhere in
 * this package. `NoProviderLiteralInTheCycleTest` fails the build if a provider name appears in the code
 * here, because that is how a shared core stops being shared: one reasonable `if` at a time.
 *
 * The cycle is assembled as DRAFTS and only then written, which is what lets anything sit between deciding
 * a line and persisting it. The order's total is the sum of its lines rather than a number carried
 * alongside them, so a step that adds a line cannot leave a total that disagrees with what it totals.
 *
 * Two things are still deliberately not here, each its own piece of work rather than an omission: coupon
 * lines, and the local invoice raised from a paid order. Both hook onto the order and its items, which
 * this assembles — so they are additions to what it produces rather than changes to how it collects.
 */
final readonly class LocalBillingEngine implements BillingEngine
{
    public function __construct(
        private string $provider,
        private PaymentRails $rails,
        private CreditLedger $credit,
        /**
         * Injected rather than reached for through the global `config()`.
         *
         * That helper lives in laravel/framework's Foundation, which this package deliberately does not
         * depend on — it requires focused `illuminate/*` components instead, and an install without
         * Foundation would fatal on the call rather than degrade. LeanDependencyContractTest holds the
         * line, and it caught this one.
         */
        private Repository $config,
        /**
         * Optional, and null means the chain is simply not there.
         *
         * A second driver constructs this engine directly — that is the whole of what adopting the cycle
         * costs — so a required parameter here would be a breaking change to the one seam this class
         * exists to keep cheap. An install that configures no steps behaves exactly as it did before the
         * chain existed.
         */
        private ?OrderItemPreprocessorChain $preprocessors = null,
        /**
         * Optional for the same reason as the chain, and null keeps the previous behavior exactly: the
         * cycle is priced from the tier and the subscription's own lines are not consulted.
         */
        private ?CycleItemPricer $pricer = null,
        /** Optional for the same reason; null means a redeemed coupon does not reach the cycle. */
        private ?CycleCouponApplier $coupons = null,
        /** Optional for the same reason; null means a local cycle raises no invoice of its own. */
        private ?OrderInvoiceIssuer $invoices = null,
        /**
         * Optional; null means a failed cycle is not retried on a ladder and simply stays past_due.
         *
         * That was the behavior before this parameter existed, and it was not a policy — it was an
         * oversight nobody could see: the order stayed `failed`, the claim refused to reopen it, and the
         * money was never collected again.
         */
        private ?ConfigDunningLadder $ladder = null,
        /**
         * The driver's capabilities, so a mandate whose method cannot be charged off-session is refused.
         *
         * Optional and null means no check, which is what every existing caller gets. The capability was
         * DECLARED by both shipped drivers and read by nothing — a value that describes the system and
         * changes none of its behavior is indistinguishable from one that is wrong.
         */
        private ?DriverCapabilities $capabilities = null,
    ) {}

    public function tick(?DateTimeInterface $now = null): void
    {
        $moment = $now instanceof DateTimeInterface ? Carbon::instance($now) : Carbon::now();

        // `cursor()` rather than `get()`: the run is a scheduled sweep over the whole table, and holding
        // every due subscription in memory is the shape that works in development and dies on the install
        // that most needs it to work.
        $due = Subscription::query()
            ->where('provider', $this->provider)
            ->dueForProcessing($moment)
            ->orderBy('id')
            ->cursor();

        foreach ($due as $subscription) {
            try {
                $this->processCycle($subscription, $moment);
            } catch (Throwable $failure) {
                // Logged and skipped, never rethrown. The alternative is a run that stops here, and the
                // subscribers after this one are then not billed at all — silently, because the exception
                // reads as "the command failed" rather than as "nineteen people were not charged".
                Log::error('billing: a due cycle could not be processed', [
                    'subscription' => $subscription->getKey(),
                    'provider' => $this->provider,
                    'reason' => $failure->getMessage(),
                ]);
            }
        }
    }

    /**
     * Assemble and collect one due cycle.
     *
     * The order is claimed and committed BEFORE the provider is called, so the claim survives whatever the
     * call does — including a timeout that leaves us not knowing whether money moved. On the next tick the
     * claim is already held, so the cycle is not re-billed; what it needs instead is reconciliation against
     * the provider, which is the webhook's job rather than this loop's.
     */
    private function processCycle(Subscription $subscription, Carbon $moment): void
    {
        $plan = $this->planFor($subscription);

        if (! $plan instanceof Money) {
            // A subscription whose tier carries no price cannot be billed, and guessing an amount is the
            // one response worse than not billing. Left for the operator to see rather than advanced.
            Log::warning('billing: a due subscription has no priceable tier', [
                'subscription' => $subscription->getKey(),
                'tier' => $subscription->tier_key,
            ]);

            return;
        }

        $drafts = $this->assembleDrafts($subscription, $plan);
        $total = $this->totalOf($drafts, $plan->currency);

        $order = $this->claimCycle($subscription, $total, $drafts, $moment);

        if (! $order instanceof Order) {
            return;
        }

        $mandate = PaymentMandate::defaultFor($subscription->owner_type, $subscription->owner_id, $this->provider);

        if (! $mandate instanceof PaymentMandate || ! $this->chargeableOffSession($mandate)) {
            // TWO DIFFERENT PEOPLE ARRIVE HERE, and only one of them owes anything.
            //
            // A subscriber whose method was withdrawn or revoked is in arrears, and the ladder a refusal
            // starts is the right answer. A mandate on a method the provider cannot charge off-session is
            // the same situation arriving differently — sending it anyway spends a round trip to be told
            // what we already knew.
            //
            // Somebody whose CARD-LESS TRIAL just ended is not that person. They were told no card was
            // needed, no payment was ever attempted with their consent, and no charge has ever succeeded on
            // this subscription. Dunning them mails "your payment failed" about money that never existed
            // and writes a delinquency entry against a prospect, which support then has to unwind. The
            // honest state is `incomplete` — the first payment is unconfirmed, access stops because the
            // free period is over, and no ladder starts.
            //
            // `incomplete_expired`, not `incomplete`, and the difference is the whole exit. Both clear the
            // schedule, so the sweep stops selecting a row nothing can be done about — but `incomplete` is
            // one of the states `LocalSubscriptionStarter::alreadySubscribed()` PROTECTS, and nothing in
            // the package re-arms a cleared schedule. The comment that used to stand here said the customer
            // adding a payment method moves it on; nothing does. Adding a card writes a mandate with no
            // matching intent, which the mandate effect deliberately ignores, so the row sat there forever:
            // no access, never charged, and the Subscribe button refusing them by name. `incomplete_expired`
            // means exactly what happened — the first payment was abandoned — and the starter already reads
            // it as not-a-subscription, so pressing Subscribe again works and goes through a real checkout.
            //
            // That exit is only safe because a subscription trial is now once per owner; without it, coming
            // back would hand out another free trial, and again after that.
            if ($subscription->status === SubscriptionState::Trialing->value && $this->neverCollected($subscription)) {
                $subscription->update([
                    'status' => SubscriptionState::IncompleteExpired->value,
                    'scheduled_processing_at' => null,
                ]);

                $order->update(['status' => OrderStatus::Failed, 'processed_at' => Carbon::now()]);

                Log::info('billing: a trial ended with no payment method, so nothing was collected', [
                    'subscription' => $subscription->getKey(),
                    'order' => $order->id,
                ]);

                return;
            }

            $this->recordFailure($subscription, $order, $total, 'no_usable_mandate');

            return;
        }

        // SPENT under the balance's own lock, and the charge derived from what actually moved — never from
        // a figure decided earlier and debited later. A refusal gives it back ({@see self::returnCredit()}),
        // which is the property that makes spending first safe.
        $owner = $this->ownerOf($subscription);
        $spend = $this->spendCredit($owner, $order, $total);
        $due = $total->minus($spend);

        if ($due->isZero()) {
            // A zero charge is not a small charge — providers refuse it — and a refusal here would put a
            // fully prepaid subscriber into dunning for having enough credit. Nothing moved at the
            // provider, but the credit DID pay for the cycle, and it is already spent.
            $this->recordSuccess($subscription, $order, $total, null);

            return;
        }

        $result = $this->rails->offSessionCharge(
            $due,
            $mandate->toReference(),
            (string) $order->id,
            null,
            $this->narrate($subscription, $order),
        );

        if ($result->successful) {
            $this->recordSuccess($subscription, $order, $due, $result->reference);

            return;
        }

        // NOT SETTLED IS NOT REFUSED, and reading only `successful` said it was. `ChargeResult` states the
        // distinction in its own comment — "`requiresAction` and `pending` are NOT failures" — and offers
        // `failed()` for exactly this branch. It was never asked.
        //
        // What that cost is the ordinary European path, not an edge case. A SEPA direct debit is Mollie's
        // main recurring method and sits at `open` for DAYS. Every such cycle was booked as a failure: the
        // credit handed back, the subscription moved to past_due, and the customer mailed "your payment
        // failed" while their money was on its way. Then the dunning ladder, whose rungs are measured in
        // days, retried it — by which time every provider's idempotency window has closed, so the retry is
        // a second real charge. The subscriber pays one cycle twice and is dunned in between.
        //
        // So the cycle is HELD instead. The order keeps the provider's reference and stays `processing`,
        // which is what that status means; the credit stays spent, because the payment it paid for is
        // still live; and nothing is dunned, because nothing has been refused. Settling it is the webhook's
        // job ({@see self::settle()}), and so is turning it into a real failure if the debit bounces.
        if (! $result->failed()) {
            $order->update(['payment_reference' => $result->reference]);

            Log::info('billing: a cycle charge is in flight and has not settled yet', [
                'subscription' => $subscription->getKey(),
                'order' => $order->id,
                'reference' => $result->reference,
            ]);

            return;
        }

        // Before recordFailure, so the balance is whole again by the time the ladder is scheduled: the
        // retry reassembles this cycle from scratch and must find the credit where the customer left it.
        $this->returnCredit($owner, $order, $spend);

        $this->recordFailure($subscription, $order, $due, $result->failureReason ?? 'charge_refused');
    }

    /**
     * Close a cycle whose charge has finally settled at the provider.
     *
     * The half that did not exist. Until now the ONLY writer of a paid order was {@see self::recordSuccess()}
     * inside the synchronous tick, so a charge that settled later — every bank debit — had nowhere to land.
     * That is why the pending branch above could not simply hold the order: holding it would have stranded
     * it. The two are one change.
     *
     * Public because a webhook effect calls it, and deliberately the only thing that is: the effect stays a
     * lookup and a handoff, and every decision about the cycle's state stays in the engine that owns it.
     *
     * Matched on the PROVIDER'S reference rather than an order id in metadata. It is what the payment
     * event carries, it is unique at the provider, and it is already on the row — a second identifier
     * threaded through the webhook would be a second version of the same fact.
     *
     * A reference that matches nothing is not an error. Every driver's payment events reach every
     * registered effect, so this is asked about one-time charges, add-ons and other installs' rows all day;
     * answering "not mine" quietly is the only thing that can be right.
     */
    public function settle(string $paymentReference, ?string $currency = null): void
    {
        $order = Order::query()
            ->where('provider', $this->provider)
            ->where('payment_reference', $paymentReference)
            ->where('status', OrderStatus::Processing)
            ->whereNotNull('subscription_id')
            ->first();

        if (! $order instanceof Order) {
            return;
        }

        $subscription = Subscription::query()->find($order->subscription_id);

        if (! $subscription instanceof Subscription) {
            // The subscription was erased while its charge was in flight. Nothing to advance, and leaving
            // the order `processing` would keep it in the recovery's sight forever — so it is closed with
            // what actually happened to the money.
            $order->update(['status' => OrderStatus::Paid, 'processed_at' => Carbon::now()]);

            return;
        }

        $this->recordSuccess(
            $subscription,
            $order,
            Money::of($order->total_minor, $currency ?? $order->currency),
            $paymentReference,
        );
    }

    /**
     * Give back the credit this order already spent, read from the order's own line.
     *
     * The line is written in the same transaction as the debit, so a line that is still there means the
     * ledger was never credited back — one fact in one place, rather than a second record that drifts the
     * first time either is written alone.
     */
    private function returnSpentCredit(Subscription $subscription, Order $order): void
    {
        $this->returnSpentCreditTo($this->ownerOf($subscription), $order);
    }

    /**
     * The same return, keyed by the owner rather than by a subscription.
     *
     * A cycle can outlive the subscription that started it, and the credit it spent is still the customer's
     * money — so a caller holding only the order resolves the owner from the morph pair the order carries
     * and comes in here. Written as one body rather than two that read the same line the same way: a second
     * copy is a second early return, a second place to forget the sign, and a branch that has to be covered
     * twice to mean anything.
     */
    private function returnSpentCreditTo(?Model $owner, Order $order): void
    {
        $line = $order->items()->where('type', OrderItemType::Credit)->first();

        if (! $line instanceof OrderItem) {
            return;
        }

        // No zero guard, because a zero line cannot exist: `spendCredit()` writes one only when the spend is
        // POSITIVE, and `returnCredit()` refuses a non-positive amount on its own. A guard here would be a
        // branch no run can enter, which reads as protection and protects nothing.
        $this->returnCredit($owner, $order, Money::of(abs((int) $line->total_minor), $order->currency));
    }

    /**
     * Turn a cycle whose in-flight charge came back refused into a real failure.
     *
     * A bank debit can bounce days after it was accepted, and THAT is the moment dunning belongs to — not
     * the moment the payment was created, which is when it used to fire. The credit goes back here for the
     * same reason it does on a synchronous refusal: the retry reassembles the cycle from scratch and must
     * find the balance where the customer left it.
     */
    public function fail(string $paymentReference, string $reason = 'charge_refused'): void
    {
        $order = Order::query()
            ->where('provider', $this->provider)
            ->where('payment_reference', $paymentReference)
            ->where('status', OrderStatus::Processing)
            ->whereNotNull('subscription_id')
            ->first();

        if (! $order instanceof Order) {
            return;
        }

        $subscription = Subscription::query()->find($order->subscription_id);

        if (! $subscription instanceof Subscription) {
            $order->update(['status' => OrderStatus::Failed, 'processed_at' => Carbon::now()]);

            return;
        }

        $this->returnSpentCredit($subscription, $order);

        $this->recordFailure($subscription, $order, Money::of($order->total_minor, $order->currency), $reason);
    }

    /**
     * Release a claim that was abandoned, so the cycle can be billed again.
     *
     * The failure this exists for is silent by construction. `claimCycle()` refuses to touch an order that
     * is still `processing`, which is exactly right while a charge is in flight and exactly wrong once the
     * process making it has died: the cycle stays claimed, no tick reopens it, no webhook can arrive
     * because no payment was ever created, and the subscriber is simply never billed again. The only trace
     * is that nothing happens. Since the credit is now spent under the row lock BEFORE the charge, what
     * sits in that window is the customer's balance rather than one uncollected cycle.
     *
     * ## Why this is not a sweep
     *
     * Nothing here can prove the provider was never called. The engine writes the payment reference as soon
     * as the provider answers — in either direction — so its absence covers every case except the one that
     * matters: a process killed mid-call, which may have created a payment and recorded nothing. An
     * automatic retry hours later is past the idempotency window that would have collapsed it, so it would
     * take the money a second time; `billing:doctor` has always told operators as much in as many words,
     * and a sweep that acted anyway would be contradicting a shipped diagnostic.
     *
     * So the decision is an operator's, made after looking at the provider, and this method is what carries
     * it out. What it does NOT do is charge: it puts the cycle back in the state an ordinary refusal leaves
     * it in, and the next tick reopens, reprices and collects it — through the SAME order, so the
     * idempotency key is the one the abandoned attempt used.
     *
     * No `PaymentFailed` is dispatched, and that is not an omission. Nothing failed on the customer's side;
     * telling them their payment was refused would be a false statement about their bank, and the dunning
     * ladder it starts is a sequence of increasingly severe emails about a charge that was never made.
     *
     * @return bool whether the claim was released; false when the order is not an abandoned claim
     */
    public function releaseAbandonedClaim(Order $order, ?CarbonInterface $now = null): bool
    {
        // ONE transaction around the read AND the writes, not a lock followed by them. A `lockForUpdate`
        // outside a transaction is released the moment its own statement commits, so the row is free again
        // before the credit is returned — the lock would read as protection while protecting nothing, and
        // the window it appears to close is the one where the money is handed back twice.
        $released = DB::transaction(function () use ($order, $now): bool {
            // Re-READ under the lock, not re-checked on the instance handed in. This is the only place the
            // decision is made, and it returns money to a balance and reopens a billable cycle: an operator
            // reads a confirmation, thinks, and answers, and in that gap a webhook can land or a second
            // operator can act on the same row. Checking the caller's copy would decide from the row as it
            // looked before the pause.
            $locked = Order::query()->lockForUpdate()->find($order->getKey());

            if (! $locked instanceof Order || ! $locked->isAbandonedClaim($now)) {
                return false;
            }

            $subscription = Subscription::query()->find($locked->subscription_id);

            // A cycle can outlive its subscription. There is then nothing to bill again — but the credit is
            // still the customer's, and giving it back is most of the reason this exists. The owner comes
            // off the ORDER, which carries the same morph pair the deleted subscription did.
            $subscription instanceof Subscription
                ? $this->returnSpentCredit($subscription, $locked)
                : $this->returnSpentCreditTo($this->ownerFor($locked->owner_type, $locked->owner_id), $locked);

            $locked->update(['status' => OrderStatus::Failed, 'processed_at' => Carbon::now()]);

            return true;
        });

        if ($released) {
            // Logged after the commit rather than inside it: a line stating that a claim was released,
            // written by a transaction that then rolled back, is a record of something that did not happen.
            Log::warning('billing: an abandoned claim was released and the cycle is billable again', [
                'order' => $order->getKey(),
            ]);
        }

        return $released;
    }

    /**
     * The lines this cycle carries, after any configured step has had them.
     *
     * The plan line is always first and always present — a cycle with no plan line is not a cycle — and a
     * step is free to add to it, reprice it, or remove it entirely if the application bills purely on
     * consumption.
     *
     * @return list<OrderItemDraft>
     */
    private function assembleDrafts(Subscription $subscription, Money $plan): array
    {
        $drafts = $this->linesOf($subscription);

        if ($drafts === []) {
            $drafts = [new OrderItemDraft(
                $subscription->tier_key ?? 'subscription',
                $plan->minorUnits,
                1,
                $plan->currency,
                OrderItemType::Subscription,
            )];
        }

        if ($this->preprocessors instanceof OrderItemPreprocessorChain) {
            $drafts = $this->preprocessors->handle($drafts, $subscription);
        }

        if ($this->periodWasCoveredByATrial($subscription)) {
            $drafts = $this->planWaived($drafts);
        }

        // After the chain, so a discount applies to everything the cycle actually carries — a metered
        // line a step added included. Before the credit offset, which happens against the total: credit
        // is payment already belonging to the customer, and spending it on an amount the discount was
        // about to remove would take money for something never owed.
        //
        // The waiver sits between the two on purpose. After the chain, because the free trial is a
        // promise the plan screen prints and a step that knows nothing about trials must not be able to
        // reprice it back. Before the discount, because a coupon whose gross has been waived to zero is
        // then refused by the applier's own positive-gross guard — so a customer does not spend one of
        // their discounted cycles on a cycle nobody charged them for.
        return $this->discounted($subscription, $drafts);
    }

    /**
     * Whether the days this cycle is about to close were inside the customer's free trial.
     *
     * The engine bills in ARREARS — an order names the period it closes, and the payment at checkout is a
     * verification amount rather than the plan price — so the cycle that closes a trial named the trial's
     * own days and charged the full plan for them. Measured over two ticks of a fourteen-day trial: 1900
     * for `[2026-08-18 .. 2026-09-01]`, then 1900 again for the month after. The trial did not merely fail
     * to be free; it pulled the first payment forward by its own length, so taking the offer cost MORE
     * than declining it, against a screen shipping "Includes a :days-day free trial." in seven languages.
     *
     * Read off the PERIOD, never off the presence of a trial date. `trial_ends_at` outlives the trial
     * deliberately — `StartSubscriptionOnMandate` preserves it as the evidence that this owner has already
     * had their one — so a waiver keyed on the column would make every cycle of every returning customer
     * free, forever.
     */
    private function periodWasCoveredByATrial(Subscription $subscription): bool
    {
        $trialEnd = $subscription->trial_ends_at;
        $periodEnd = $subscription->current_period_end;

        return $trialEnd !== null
            && $periodEnd !== null
            && $periodEnd->lessThanOrEqualTo($trialEnd);
    }

    /**
     * The same lines with the recurring plan priced at zero.
     *
     * Zeroed rather than removed, and scoped to the one type the enum already defines as "a recurring plan
     * charge for the cycle". Removing it would leave a free period with no lines at all — a numbered
     * document that says nothing about what the days were free OF — and would silently drop the plan for a
     * consumer whose `CycleItemPricer` expresses it as several lines instead of one.
     *
     * Metered consumption and add-ons are not the plan and stay billable: a free trial is an offer about
     * the recurring charge, and usage an application priced for those days is something that happened.
     *
     * @param  list<OrderItemDraft>  $drafts
     * @return list<OrderItemDraft>
     */
    private function planWaived(array $drafts): array
    {
        $waived = [];

        // A loop with an `if` rather than the obvious `array_map` with a ternary, and the reason is the
        // coverage floor rather than taste: the else-branch of a ternary spread over several lines is
        // never marked as executed, so a file holding one cannot reach 100% however many cases are
        // written for it.
        foreach ($drafts as $draft) {
            if ($draft->type !== OrderItemType::Subscription) {
                $waived[] = $draft;

                continue;
            }

            $waived[] = new OrderItemDraft(
                $draft->description,
                0,
                $draft->quantity,
                $draft->currency,
                $draft->type,
                $draft->metadata,
            );
        }

        return $waived;
    }

    /**
     * The same lines with a redeemed coupon's discount line appended, if one applies.
     *
     * The cycle's own start is the period key. A coupon counts CYCLES, and the cycle is exactly what that
     * timestamp identifies — the same value the order claim is unique on, so the two cannot disagree
     * about which cycle this is.
     *
     * @param  list<OrderItemDraft>  $drafts
     * @return list<OrderItemDraft>
     */
    private function discounted(Subscription $subscription, array $drafts): array
    {
        $start = $subscription->current_period_start;

        if (! $this->coupons instanceof CycleCouponApplier || $start === null) {
            return $drafts;
        }

        $owner = $this->ownerOf($subscription);

        return $owner instanceof Model
            ? $this->coupons->apply($drafts, $subscription, $owner, $start->toDateString())
            : $drafts;
    }

    /**
     * The subscription's own lines, priced by the resolver that was already bound for them.
     *
     * Empty for a subscription that carries none, which is the ordinary case and the one that keeps
     * billing the tier price. A subscription that HAS lines is billed from them, because a tier price
     * cannot express three lines and would silently bill as though there were one.
     *
     * @return list<OrderItemDraft>
     */
    private function linesOf(Subscription $subscription): array
    {
        if (! $this->pricer instanceof CycleItemPricer) {
            return [];
        }

        $owner = $this->ownerOf($subscription);

        return $owner instanceof Model ? $this->pricer->drafts($subscription, $owner) : [];
    }

    /**
     * What the order comes to, summed from its lines rather than carried alongside them.
     *
     * Floored at zero because a chain can legitimately produce a negative sum — a discount larger than the
     * plan, a credit line — and a negative order total is not a refund: it is a charge the provider would
     * refuse and an amount the invoice cannot state. Zero routes into the path that already handles a
     * fully covered cycle, which books it as collected without calling the provider at all.
     *
     * @param  list<OrderItemDraft>  $drafts
     */
    private function totalOf(array $drafts, string $currency): Money
    {
        $sum = 0;

        foreach ($drafts as $draft) {
            $sum += $draft->totalMinor();
        }

        return Money::of(max(0, $sum), $currency);
    }

    /**
     * Claim this cycle by creating its order, or return null when somebody already has it.
     *
     * The unique constraint is what makes the claim atomic, so a concurrent tick loses the insert rather
     * than racing on a read. An order that already exists and is not still open belongs to a completed
     * attempt and is left alone.
     */
    /** @param  list<OrderItemDraft>  $drafts */
    private function claimCycle(Subscription $subscription, Money $amount, array $drafts, Carbon $moment): ?Order
    {
        return DB::transaction(function () use ($subscription, $amount, $drafts, $moment): ?Order {
            $existing = Order::query()
                ->where('subscription_id', $subscription->getKey())
                ->where('period_start', $subscription->current_period_start)
                ->first();

            if ($existing instanceof Order) {
                // A FAILED order for this cycle is the dunning retry, not a completed attempt: the money
                // was never collected, so the cycle is reopened rather than left alone. Anything else —
                // paid, or still processing — belongs to an attempt that is not ours to touch.
                if ($existing->status !== OrderStatus::Failed) {
                    return null;
                }

                // REPRICED, not merely reopened. `processCycle()` reassembles the drafts and re-totals
                // them on every tick, and the charge that follows uses THAT figure — so a cycle whose
                // price moved during the dunning window (a late usage flush landing in the still-open
                // period, a coupon whose `expires_at` passed, seats changed while the customer was
                // past due) would have been charged one amount over an order still stating the first
                // attempt's. `OrderInvoiceIssuer` copies the ORDER, so that is a numbered, immutable
                // tax document for a sum nobody was charged, and a booking batch off by the difference.
                //
                // The lines are REPLACED rather than added to: the previous attempt's items include any
                // credit line it booked, and keeping them would describe a cycle nobody priced.
                $existing->update([
                    'status' => OrderStatus::Processing,
                    'processed_at' => null,
                    'total_minor' => $amount->minorUnits,
                    'currency' => $amount->currency,
                ]);

                $existing->items()->delete();

                foreach ($drafts as $draft) {
                    $existing->items()->create($draft->toAttributes());
                }

                return $existing;
            }

            $order = Order::query()->create([
                'owner_type' => $subscription->owner_type,
                'owner_id' => $subscription->owner_id,
                'provider' => $this->provider,
                'subscription_id' => $subscription->getKey(),
                'total_minor' => $amount->minorUnits,
                'currency' => $amount->currency,
                'status' => OrderStatus::Processing,
                'period_start' => $subscription->current_period_start,
                'period_end' => $subscription->current_period_end,
            ]);

            foreach ($drafts as $draft) {
                $order->items()->create($draft->toAttributes());
            }

            unset($moment);

            return $order;
        });
    }

    /**
     * Spend what this cycle can of the owner's credit, say so on the order, and answer what moved.
     *
     * BEFORE the charge, deliberately, and that is a reversal of the shape this replaces. That one decided
     * a figure here and debited it after the provider answered — which fixed a refused charge eating the
     * balance, and opened a wider hole in its place. Between the decision and the debit sat an outbound
     * HTTP call, seconds on a good day and a full timeout on the day it matters, and the balance was read
     * without a lock at one end and applied without a re-check at the other.
     *
     * Two cycles for the same owner — two local drivers, or one `billing:cycle` run overlapping itself,
     * which this engine's own docblock calls ordinary — then both read the same balance while the other was
     * inside its charge, and both spent it. The balance goes NEGATIVE, and negative is a one-way street:
     * every later cycle reads a non-positive figure and skips the offset, so nothing ever collects it back.
     * Two ledger entries, each reading like a correct offset, and the seller permanently out the smaller.
     *
     * So the read, the cap and the debit are ONE movement under the balance row's lock
     * ({@see CreditLedger::spendUpTo()}), and what makes spending first safe is that a refusal returns it
     * ({@see self::returnCredit()}).
     *
     * ## What this trade costs, stated rather than hidden
     *
     * A worker that dies between this commit and the provider's answer leaves the order `processing` with
     * the credit already spent, and `claimCycle()` will not reopen a processing order — deliberately, so a
     * concurrent tick cannot charge the same cycle twice. That cycle is then never collected and the credit
     * sits against it.
     *
     * The window is not new: an order stranded `processing` by a crash was already unreachable, and the
     * previous shape lost the charge instead of the credit. What is new is that the credit is inside it.
     * It is the smaller exposure of the two — it needs a crash in a seconds-wide window, where the race it
     * replaces needed only two ordinary cycles overlapping — and the order carries the credit LINE, so an
     * operator can see exactly what happened rather than inferring it from a balance.
     *
     * ## It is closed now, and the shape of the answer is the interesting part
     *
     * {@see self::releaseAbandonedClaim()} returns the credit and puts the cycle back on the billing path,
     * and `billing:release-claim` is where an operator invokes it. It is NOT a sweep, and the reason is the
     * question this paragraph used to leave open — what a recovered order does about a charge that may yet
     * land. Nothing in this process can answer it: the payment reference is written the moment the provider
     * replies, so its absence covers every case except a worker killed mid-call, which may have created a
     * payment and recorded nothing. A prompt retry would collapse onto that payment through the idempotency
     * key; a retry late enough to tell a dead claim from a live one has outlived the key. Between racing a
     * live charge and taking the money twice there is no threshold a sweep could pick, so the decision
     * belongs to whoever can look at the provider.
     *
     * What that leaves inside this window is unchanged in kind and much shorter in practice: until somebody
     * releases the claim, the credit sits against an uncollected cycle — visible as the credit LINE on the
     * order, and reported by `billing:doctor` from six hours on.
     */
    private function spendCredit(?Model $owner, Order $order, Money $amount): Money
    {
        // The pair, not either half: a spend is only ever positive when an owner was found to read a
        // balance off, so this is one condition asked once rather than two guards of which the second can
        // never fire on its own. A cycle that costs nothing has nothing to offset either, and asking for
        // it was what stranded the order: CreditLedger refuses a non-positive debit, deliberately, so that
        // a caller cannot smuggle a credit through it.
        if (! $owner instanceof Model || ! $amount->isPositive()) {
            return Money::of(0, $amount->currency);
        }

        return DB::transaction(function () use ($owner, $order, $amount): Money {
            $spend = $this->credit->spendUpTo($owner, $amount, CreditReason::ChargeOffset, CreditSource::for($order));

            if (! $spend->isPositive()) {
                return $spend;
            }

            $order->items()->create([
                'description' => 'Credit applied',
                'unit_price_minor' => -$spend->minorUnits,
                'quantity' => 1,
                'total_minor' => -$spend->minorUnits,
                'currency' => $spend->currency,
                'type' => OrderItemType::Credit,
            ]);

            // The header moves WITH the line, in the same transaction. It was written when the cycle was
            // claimed and the line added afterwards, so a partly credited cycle stated one number over
            // lines that added to another — and the invoice raised from that order copies the HEADER. A
            // numbered tax document then tells the customer the full amount was paid when less was taken.
            $order->update(['total_minor' => $order->total_minor - $spend->minorUnits]);

            return $spend;
        });
    }

    /**
     * Give the credit back, because the cycle it was spent on was never collected.
     *
     * Without this the customer pays twice: the debit landed, the provider refused the remainder, and the
     * dunning retry reassembles the cycle from scratch, finds a balance of zero and charges the FULL amount
     * — successfully. The credit is gone and the invoice is paid in full, behind a ledger entry that reads
     * like a deliberate offset.
     *
     * The order is put back the way it was found, header and line together, so an operator looking at a
     * failed order sees what was actually attempted rather than a credit that is no longer spent.
     */
    private function returnCredit(?Model $owner, Order $order, Money $spend): void
    {
        if (! $owner instanceof Model || ! $spend->isPositive()) {
            return;
        }

        DB::transaction(function () use ($owner, $order, $spend): void {
            $this->credit->credit($owner, $spend, CreditReason::ChargeOffsetReturned, CreditSource::for($order));

            $order->items()->where('type', OrderItemType::Credit)->delete();
            $order->update(['total_minor' => $order->total_minor + $spend->minorUnits]);
        });
    }

    /**
     * Whether this subscription has never successfully collected anything.
     *
     * The SECOND half of the test, and it needs the first: a status alone would put a subscriber who paid
     * for a year and then went back into a trial — which this package does not produce, but a consumer
     * writing rows itself might — on the wrong side. Read off the ORDERS, because a status says what
     * somebody currently is and the question here is what they have ever been.
     *
     * Both halves narrow it to one situation: a trial reaching its end with no first payment behind it. A
     * NEVER-COLLECTED ACTIVE subscription is a different person entirely — they subscribed, their method
     * broke on the first cycle, and that is arrears.
     */
    private function neverCollected(Subscription $subscription): bool
    {
        return ! Order::query()
            ->where('subscription_id', $subscription->getKey())
            ->where('status', OrderStatus::Paid)
            ->exists();
    }

    /** The cycle was paid: book it, move the cycle on, and say so. */
    private function recordSuccess(Subscription $subscription, Order $order, Money $amount, ?string $reference): void
    {
        DB::transaction(function () use ($subscription, $order, $reference): void {
            $order->update([
                'status' => OrderStatus::Paid,
                'processed_at' => Carbon::now(),
                'payment_reference' => $reference,
            ]);

            // Built from the config this engine already holds, rather than taken as a constructor
            // parameter: the engine is constructed by hand in a dozen places, and a new required
            // argument would be a change to every one of them for a reader that needs nothing else.
            //
            // A SHARED class rather than a line here, because the effect that writes a subscription's
            // FIRST period asks the same question. Two readings of one fact do not fail loudly when
            // they drift — the first cycle and every cycle after it simply describe different
            // subscriptions, which reads on an invoice as a period somebody mistyped.
            $subscription->advanceCycle(new TierInterval($this->config)->for($subscription->tier_key));

            if ($subscription->status === SubscriptionState::PastDue->value) {
                $subscription->update(['status' => SubscriptionState::Active->value, 'dunning_level' => 0]);
            }

            // A collected trial is a paying customer, and the status has to say so. Left at `trialing` the
            // presenter goes on reporting a trial that ended — so the account hub says "free trial" to
            // somebody whose card is being charged every month, and any consumer gating on the state grants
            // trial terms to a full subscriber.
            //
            // Read off the DATE rather than `onTrial()`, which cannot answer this: that helper returns true
            // for any row whose status is `trialing` — deliberately, because a webhook-synced row carries
            // the canonical status and no dates. Asking it here would always say "still trialing" and the
            // transition would never happen.
            //
            // A row with no trial end is one of these too: a trial that was never dated has certainly not
            // got one running. Reaching this line at all means money was just collected from it.
            if ($subscription->status === SubscriptionState::Trialing->value
                && $subscription->trial_ends_at?->isFuture() !== true) {
                $subscription->update(['status' => SubscriptionState::Active->value]);
            }
        });

        // Outside the transaction above, and deliberately. The money is collected and the cycle has moved
        // on; raising the document is a separate concern, and rolling those two back together would undo a
        // correct cycle because a piece of paper failed. The issuer swallows its own failure for the same
        // reason — a missing invoice is recoverable, a cycle that reports failure after taking the money
        // is not.
        $this->invoices?->issue($order->fresh() ?? $order);

        Event::dispatch(new PaymentSucceeded((string) $subscription->owner_id, $amount, $reference ?? (string) $order->id));
    }

    /**
     * The cycle was not paid: book it and hand the subscriber to dunning.
     *
     * The cycle is deliberately NOT advanced. Moving it would re-date the service the customer is paying
     * for and lose the unpaid period entirely; the retry belongs inside the period it failed in, which is
     * what `scheduled_processing_at` being its own column makes possible.
     */
    private function recordFailure(Subscription $subscription, Order $order, Money $amount, string $reason): void
    {
        DB::transaction(function () use ($subscription, $order): void {
            $order->update(['status' => OrderStatus::Failed, 'processed_at' => Carbon::now()]);

            $since = $subscription->delinquent_since ?? Carbon::now();

            $subscription->update([
                'status' => SubscriptionState::PastDue->value,
                'delinquent_since' => $since,
                // Without this the cycle was never retried at all: the row kept the due date it had
                // already been processed on, the claim refused to reopen a failed order, and the
                // subscriber sat in past_due while nobody ever collected again.
                'scheduled_processing_at' => $this->nextAttemptAfter($since),
            ]);
        });

        Log::info('billing: a due cycle was not collected', [
            'subscription' => $subscription->getKey(),
            'order' => $order->id,
            'reason' => $reason,
        ]);

        Event::dispatch(new PaymentFailed((string) $subscription->owner_id, $amount, (string) $order->id));
    }

    /**
     * Resolve the subscription's owner back to a model through the morph map.
     *
     * The same shape ScheduledSwapRunner and AdvanceDunningCommand use, and for the same reason: the owner
     * is the CONSUMER's model, so there is no relation on the subscription to follow — only a morph type
     * the application registered. An owner that cannot be resolved (deleted between the sweep selecting the
     * row and this running) yields null rather than an exception, because a missing owner is an ordinary
     * race here, not a defect.
     */
    /**
     * When the next collection attempt is due, or null once the ladder is exhausted.
     *
     * The clock runs from when the arrears STARTED, not from the last attempt — a ladder anchored to
     * attempts drifts further out with every retry, so a subscriber who fails on day one and again on day
     * four would reach the final notice a week later than the configuration says.
     *
     * Null when no rung is left. The subscription then stays past_due for the expiry sweep to act on
     * rather than being retried forever: a card that has refused three times over two weeks is not going
     * to succeed on the fourth, and each attempt costs a fee and counts against the merchant.
     */
    private function nextAttemptAfter(CarbonInterface $since): ?Carbon
    {
        if (! $this->ladder instanceof ConfigDunningLadder) {
            return null;
        }

        $elapsed = Carbon::instance($since)->diffInDays(Carbon::now());

        foreach ($this->ladder->levels() as $level) {
            if ($level->afterDays > $elapsed) {
                return Carbon::instance($since)->addDays($level->afterDays);
            }
        }

        return null;
    }

    /**
     * Whether this mandate's method can carry a merchant-initiated charge.
     *
     * True when no capabilities were supplied, because that is the behavior every caller had before this
     * check existed and silently refusing every cycle would be a far worse default than not checking.
     *
     * A mandate with no method recorded is allowed through: the provider issued it, and refusing on
     * missing metadata would strand a subscriber over a blank column rather than a real constraint.
     */
    private function chargeableOffSession(PaymentMandate $mandate): bool
    {
        if (! $this->capabilities instanceof DriverCapabilities) {
            return true;
        }

        $method = trim($mandate->method);

        return $method === '' || $this->capabilities->canRecurWith($method);
    }

    private function ownerOf(Subscription $subscription): ?Model
    {
        return $this->ownerFor($subscription->owner_type, $subscription->owner_id);
    }

    /**
     * The same lookup keyed by the morph pair itself, for a caller holding an order rather than a
     * subscription — the two rows carry the same pair, and a cycle can outlive the subscription that
     * started it.
     */
    private function ownerFor(?string $ownerType, int|string|null $ownerId): ?Model
    {
        $class = Relation::getMorphedModel((string) $ownerType) ?? $ownerType;

        if (! is_string($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        $owner = $class::query()->find($ownerId);

        return $owner instanceof Model ? $owner : null;
    }

    /**
     * What the customer should read on this charge.
     *
     * This layer is the only one that can answer it. The rails see an amount and a mandate; the order knows
     * the span; the subscription knows the tier. Assembling the sentence anywhere lower would mean handing
     * the driver a subscription, which is the coupling the two-layer split exists to prevent.
     *
     * The ORDER's period, not the subscription's current one. They hold the same value while the cycle is
     * being collected and stop agreeing the moment it advances — a charge held pending over a period
     * rollover would then be described as paying for the period AFTER the one it actually paid for.
     */
    private function narrate(Subscription $subscription, Order $order): ChargeNarrative
    {
        return new ChargeNarrative(
            $this->serviceName($subscription),
            $order->period_start,
            $order->period_end,
        );
    }

    /**
     * The tier's human name, falling back until something is left that a person can read.
     *
     * Three rungs, and the last one is what keeps this total. `ChargeNarrative` REFUSES a blank service —
     * deliberately, because a bare period in brackets reads as intentional — so a blank arriving here would
     * throw inside the cycle path and fail a charge that has nothing else wrong with it. An empty `label`
     * is not exotic: `label` is optional in the shipped config and an operator can set it to "".
     *
     * The tier KEY is the middle rung rather than a straight jump to the generic word: `pro` is a poorer
     * name than `Acme Pro` and a much better one than `Subscription`, because it still tells the subscriber
     * which of their plans this was and tells support which tier to open.
     */
    private function serviceName(Subscription $subscription): string
    {
        $tier = $subscription->tier_key;
        $label = $tier === null ? null : $this->config->get("billing.tiers.{$tier}.label");

        foreach ([$label, $tier] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return $candidate;
            }
        }

        return 'Subscription';
    }

    /** What this subscription's tier costs, or null when nothing prices it. */
    private function planFor(Subscription $subscription): ?Money
    {
        $tier = $subscription->tier_key;

        if ($tier === null) {
            return null;
        }

        $price = $this->config->get("billing.tiers.{$tier}.price_display");

        if (! is_array($price) || ! is_int($price['amount'] ?? null) || ! is_string($price['currency'] ?? null)) {
            return null;
        }

        return Money::of($price['amount'], $price['currency']);
    }
}
