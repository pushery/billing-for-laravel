<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks\Effects;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Bus;
use Pushery\Billing\Enums\ReversalCause;
use Pushery\Billing\Events\ChargebackReceived;
use Pushery\Billing\Jobs\ReverseMerchantShareForChargeback;
use Pushery\Billing\Marketplace\ClawbackCalculator;
use Pushery\Billing\Marketplace\RoutedChargeLedger;
use Pushery\Billing\Models\MerchantCharge;
use Pushery\Billing\ValueObjects\PlatformFee;

/**
 * Writes down that a lost dispute owes a clawback, and hands the provider call to a job.
 *
 * ## The split is forced, not stylistic
 *
 * Webhook effects run inside `HandleWebhookEffect`'s `DB::transaction`, which is what makes a failed effect
 * release its dedup claim instead of leaving a marker for work nobody did. The cost of that — and it is the
 * right trade — is that an effect CANNOT make a provider call outside a transaction. Opening its own gets a
 * SAVEPOINT, not a commit, so a nested `DB::transaction` reads like the promise and is not one.
 *
 * So this effect only writes the intent. `ReverseMerchantShareForChargeback` is `ShouldQueueAfterCommit`, so
 * it is enqueued only once this transaction has actually committed: a chargeback whose effect rolled back
 * cannot leave a reversal in flight against money nobody took back.
 *
 * ## The attempt row IS the claim
 *
 * `beginRefund()` writes it before anything is sent and derives the provider's idempotency key from its id.
 * Together with the effect ledger's own dedup — one claim per (provider, reference, effect) — a redelivered
 * dispute produces no second attempt and therefore no second job. Two independent guards, and they guard
 * different things: the ledger stops the effect running twice, the key stops the provider acting twice on a
 * job that did run twice.
 *
 * ## What this deliberately does not do
 *
 * It does not refund the buyer. On a chargeback the network has already taken the money back — asking the
 * rails to refund as well would return it a second time. What is owed is the merchant's share, and only
 * that.
 *
 * It also does not issue correcting documents. `CorrectChainOnChargeback` does, and it is a separate effect
 * because it answers a question this one does not ask: WHICH legs a dispute corrects turns on the ground
 * code, while what the merchant holds is the same either way.
 */
final readonly class ClaimChargebackClawback
{
    public function __construct(
        private RoutedChargeLedger $ledger,
        private ClawbackCalculator $clawbacks,
        private Repository $config,
    ) {}

    public function __invoke(ChargebackReceived $event): void
    {
        if ($this->config->get('billing.marketplace.enabled') !== true) {
            return;
        }

        $charge = $this->ledger->find($this->providerName(), $event->reference);

        // Not routed, or routed by a provider this event did not come from: there is no merchant share to
        // take back and nothing to record.
        if (! $charge instanceof MerchantCharge) {
            return;
        }

        // The transfer this reversal has to act on. A destination charge unwinds its transfer as part of the
        // dispute at the provider, so a charge with no separate transfer reference has nothing for this job
        // to reverse -- and inventing one would send a reversal at whatever the reference resolves to.
        $transfer = $charge->transfer_reference;

        if (! is_string($transfer) || $transfer === '') {
            return;
        }

        // The terms the SALE was made under. A row from before they were recorded prices a full reversal
        // identically under any rate -- nothing is left of the sale, so there is no remainder to price --
        // and a chargeback is always full: the network took the whole payment back.
        [$merchantClawback, $feeReturned] = $this->clawbacks->forRefund(
            $charge,
            $charge->frozenFee() ?? new PlatformFee,
            $charge->gross(),
        );

        $attempt = $this->ledger->beginRefund(
            $charge,
            $charge->gross(),
            $merchantClawback,
            $feeReturned,
            ReversalCause::DisputeLost,
            $event->feeAmount,
        );

        Bus::dispatch(new ReverseMerchantShareForChargeback((int) $attempt->id, $transfer));
    }

    /**
     * Which provider's rows this event is about.
     *
     * Read from the configured default rather than the event, which does not carry one -- and named here
     * rather than inlined, so the single place it is assumed is visible. A multi-provider install would
     * have to put it on the event; today the package has one active driver at a time and the charge rows
     * carry that driver's name.
     */
    private function providerName(): string
    {
        $default = $this->config->get('billing.default', 'stripe');

        return is_string($default) ? $default : 'stripe';
    }
}
