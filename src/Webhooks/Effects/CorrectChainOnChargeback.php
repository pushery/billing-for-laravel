<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks\Effects;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Contracts\CreatorTaxStatusResolver;
use Pushery\Billing\Enums\DisputeReason;
use Pushery\Billing\Events\ChargebackReceived;
use Pushery\Billing\Marketplace\RoutedRefundCorrector;
use Pushery\Billing\Models\MerchantCharge;

/**
 * Issues the correcting documents a lost chargeback owes — on the leg or legs it actually owes them on.
 *
 * ## Why this class exists
 *
 * Every part of the answer was built and none of it was reached. `DisputeReason` maps the provider's ground
 * code onto the two corrections a lost dispute can owe; `RoutedRefundCorrector::correct()` takes that reason
 * and hands it all the way into the arithmetic that decides WHICH documents exist. Both had test suites and
 * neither had a caller: a chargeback ended access and recorded the provider's fee, and issued no correcting
 * document at all.
 *
 * ## The distinction that is the whole point
 *
 * A chargeback is not one situation. Where the buyer never received what they paid for, the supply was
 * undone — both legs of the chain are corrected, and the creator's settlement goes back with it. Where the
 * payment was disputed as fraudulent or unrecognized, the creator DELIVERED: the consideration simply never
 * arrived, and it is the platform that carries the loss. Correcting the creator's leg there would take back
 * a settlement they earned, for a failure that was not theirs.
 *
 * Identical amounts, identical paperwork, opposite parties out of pocket — and nothing in the amounts can
 * tell the two apart. Only the ground code can, which is why it travels on the event.
 *
 * ## The fail-safe direction
 *
 * A missing or unrecognized ground code resolves to `Unknown`, which corrects BOTH legs. That is the
 * deliberate direction: it never leaves the outbound side understated, and an over-corrected creator leg is
 * a conversation, whereas an understated tax liability is a filing. A provider that adds a new code
 * therefore degrades into the safe answer rather than into silence.
 *
 * ## Why the status is resolved AT THE SUPPLY
 *
 * A correcting document states the taxation of the sale it corrects, not of the day it was written. Reading
 * today's standing would let a creator who has since become a small business receive a correction stating
 * tax that was correctly charged then — and the whitelist, which checks today's status, would not catch it.
 */
final readonly class CorrectChainOnChargeback
{
    public function __construct(
        private RoutedRefundCorrector $corrector,
        private CreatorTaxStatusResolver $statuses,
        private Repository $config,
    ) {}

    public function __invoke(ChargebackReceived $event): void
    {
        if ($this->config->get('billing.marketplace.enabled') !== true) {
            return;
        }

        $charge = MerchantCharge::query()
            ->where('charge_reference', $event->reference)
            ->first();

        if (! $charge instanceof MerchantCharge) {
            return;
        }

        $merchant = $charge->merchant;

        if ($merchant === null) {
            return;
        }

        // Frozen at the supply, not read as of today. A charge with no settlement date was never settled,
        // so it has no settlement document to correct and the corrector will find nothing — the moment used
        // there cannot affect an outcome, and `now()` says that plainly rather than inventing a supply date.
        $suppliedOn = $charge->settled_at instanceof Carbon
            ? CarbonImmutable::parse($charge->settled_at)
            : CarbonImmutable::now();

        $this->corrector->correct(
            $charge,
            $event->amount,
            $this->statuses->statusAt($merchant, $suppliedOn),
            CarbonImmutable::now(),
            // `??` rather than a branch: an absent reason is `Unknown`, and `Unknown` already resolves to the
            // safe both-legs correction. Writing the fallback as its own case would be a second place the
            // fail-safe direction is decided, and the two would drift.
            ($event->reason ?? DisputeReason::Unknown)->taxBaseChangeReason(),
        );
    }
}
