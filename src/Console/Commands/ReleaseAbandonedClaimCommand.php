<?php

declare(strict_types=1);

namespace Pushery\Billing\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Pushery\Billing\Enums\OrderStatus;
use Pushery\Billing\Models\Order;
use Pushery\Billing\Support\BillingManager;
use Pushery\Billing\Support\LocalBillingEngine;

/**
 * Hand a cycle back to the biller after its claim was abandoned.
 *
 * `billing:doctor` reports claims that were taken and never charged, and then tells the operator to inspect
 * them before deciding, because "a retry is only safe where the provider was never called". Until now that
 * was the end of the line: the report named the problem and nothing could act on it. The order stayed
 * `processing` forever, no tick reopened it, no webhook could arrive because no payment was ever created,
 * and the subscriber was simply never billed again.
 *
 * ## Why an operator runs this and a sweep does not
 *
 * The absence of a payment reference is the closest thing to proof that the provider was never called, and
 * it is not proof. A process killed mid-call leaves no reference behind and may still have created a
 * payment. An idempotency key would collapse a prompt retry onto that payment — but the claim has to be
 * hours old before it can be told apart from ordinary in-flight work, and by then the key has expired. A
 * sweep would therefore be choosing between acting too early, where it races a live charge, and acting too
 * late, where it takes the money a second time.
 *
 * An operator can look at the provider, which is the one place that knows. This command is what carries
 * their decision out, and it refuses everything they have not established: an order that is not a claim,
 * one that reached the provider, one still young enough to be in flight.
 *
 * ## It does not charge anything
 *
 * It returns the credit the abandoned attempt spent and puts the cycle in the state an ordinary refusal
 * leaves it in. The next scheduled run reopens it, reprices it and collects it — through the SAME order, so
 * the charge carries the idempotency key the abandoned attempt used rather than a fresh one.
 */
final class ReleaseAbandonedClaimCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'billing:release-claim
        {order : The billing order whose claim is to be released}
        {--force : Skip the production confirmation}';

    protected $description = 'Release an abandoned cycle claim so the subscriber can be billed again';

    public function handle(BillingManager $manager): int
    {
        $engine = $manager->driver()->engine();

        if (! $engine instanceof LocalBillingEngine) {
            // Under a provider-driven driver there is no claim to release: the provider runs the cycle and
            // owns its retries. Saying so beats acting on an order that means something else here.
            $this->components->error(
                'The active driver runs its subscriptions at the provider, so this package never claims a '
                .'cycle and there is nothing to release.'
            );

            return self::FAILURE;
        }

        $order = Order::query()->find($this->argument('order'));

        if (! $order instanceof Order) {
            $this->components->error('No such billing order.');

            return self::FAILURE;
        }

        // Money moves, so production asks first. --force is for an operator who has already inspected the
        // provider and is scripting the cleanup of several.
        //
        // Asked BEFORE eligibility, which costs one prompt on an order that then turns out not to be a
        // claim — and buys the thing that matters more: exactly ONE place decides, and it decides from the
        // row as it stands after the operator has answered. A version that checked here as well read the
        // caller's copy of the row, so its second look could never disagree with its first: a branch that
        // reads as protection, cannot be entered, and would have to be covered by a test that proves
        // nothing.
        if (! $this->confirmToProceed('This returns spent credit and makes the cycle billable again.')) {
            return self::FAILURE;
        }

        if (! $engine->releaseAbandonedClaim($order)) {
            // `fresh()`, not `refresh()`: the latter is findOrFail underneath and THROWS when the row is
            // gone. On the one command whose job is to explain a refusal clearly, a stack trace is the
            // worst possible answer — and a vanished row is a refusal like any other, not a crash.
            $this->components->error($this->whyNot($order->fresh() ?? $order));

            return self::FAILURE;
        }

        $this->components->info("Released the claim on order {$order->id}. Nothing was charged: the next scheduled run reprices this cycle and collects it under the same order.");

        return self::SUCCESS;
    }

    /**
     * Why this order is not an abandoned claim, in the operator's terms.
     *
     * Each branch points somewhere different, which is the whole value: "not eligible" would send somebody
     * looking for a bug in this command, and one of these three is almost always the real answer.
     */
    private function whyNot(Order $order): string
    {
        if ($order->payment_reference !== null) {
            return "Order {$order->id} reached the provider (payment {$order->payment_reference}). It is held, "
                .'not stranded: its webhook is what settles it, and releasing it would put a live payment back '
                .'on the billing path. `billing:doctor` reports a charge that has been in flight too long '
                .'separately, and the answer to that one is at the provider.';
        }

        if ($order->status !== OrderStatus::Processing) {
            return "Order {$order->id} is {$order->status->value}, not a claim in progress. A paid cycle is "
                .'finished and a failed one is already back on the billing path.';
        }

        return "Order {$order->id} was claimed ".($order->created_at?->diffForHumans() ?? 'recently')
            .', which is inside the '.Order::ABANDONED_CLAIM_HOURS.'-hour window where a charge is probably '
            .'still in flight. Releasing it now would race a live attempt and bill the cycle twice.';
    }
}
