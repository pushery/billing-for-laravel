<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Exceptions\SelfBillingAgreementMissing;
use Pushery\Billing\Models\SelfBillingAgreement;

/**
 * Refuses a self-billed document when no agreement authorizes it — the first red line of the settlement
 * chain, and the reason the whole chain is legally valid.
 *
 * A self-billed document is only an invoice if both sides agreed to the arrangement before it. So the check
 * sits at the creation call, alongside the posture and disclosure guards, not in the UI: the UI cannot
 * protect a job, a console command, or any consumer path that reaches the writer directly, and a document
 * that slips through there is a worthless one that cannot be repaired.
 *
 * The authorization is time-checked at BOTH ends against the supply date, not merely "does a record exist":
 * an agreement accepted after the supply does not reach back to cover it, and a revocation dated after the
 * supply leaves that supply covered because the arrangement was live when it happened. A creator may hold
 * several versions over time; any one that authorizes the supply is enough.
 *
 * The requirement is on by default and opts out only explicitly. A jurisdiction that does not demand a
 * prior agreement sets the switch off; a missing or non-boolean value keeps the guard on, because the
 * fail-safe here is to require the agreement, never to skip it.
 */
final readonly class SelfBillingAgreementGuard
{
    public function __construct(private Repository $config) {}

    public function assertMayIssueSelfBilledInvoice(Model $creator, CarbonInterface $supplyDate): void
    {
        if ($this->config->get('billing.marketplace.self_billing.require_agreement', true) !== true) {
            return;
        }

        $authorized = SelfBillingAgreement::query()
            ->where('merchant_type', $creator->getMorphClass())
            ->where('merchant_id', $creator->getKey())
            ->get()
            ->contains(fn (SelfBillingAgreement $agreement): bool => $agreement->authorizes($supplyDate));

        if (! $authorized) {
            throw SelfBillingAgreementMissing::forCreator($creator, $supplyDate);
        }
    }
}
