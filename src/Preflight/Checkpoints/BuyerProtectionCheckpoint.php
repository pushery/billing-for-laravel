<?php

declare(strict_types=1);

namespace Pushery\Billing\Preflight\Checkpoints;

use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Contracts\GoLiveCheckpoint;
use Pushery\Billing\Enums\GoLiveStep;
use Pushery\Billing\Exceptions\BuyerProtectionMisconfigured;
use Pushery\Billing\Marketplace\BuyerProtectionClock;
use Pushery\Billing\ValueObjects\CheckpointOutcome;

/**
 * Buyer protection, where it is switched on, can hold a payout for as long as it promises to.
 *
 * The same condition every protected sale is refused on before the buyer is charged, asked here so the operator
 * learns it before the first sale rather than from it. Two arrangements fail it: a connected-account type that
 * pays out on the provider's own schedule, which leaves nothing to hold back, and a decision deadline the provider
 * will not wait for, past which the money goes out whatever the settings say.
 *
 * Not waivable. Protection that cannot hold the money does not start holding it because its key is in a list,
 * and a waived point here would put the promise in front of buyers with nothing behind it.
 *
 * It asks a clock built on the configuration alone. The question is about configuration, and the checklist runs
 * at boot, where resolving the clock's transfer and ledger seams would be work no answer here depends on.
 */
final readonly class BuyerProtectionCheckpoint implements GoLiveCheckpoint
{
    public function __construct(private Repository $config) {}

    public function key(): string
    {
        return 'configuration.buyer_protection';
    }

    public function step(): GoLiveStep
    {
        return GoLiveStep::Configuration;
    }

    public function isBlocking(): bool
    {
        return true;
    }

    public function isWaivable(): bool
    {
        return false;
    }

    public function evaluate(): CheckpointOutcome
    {
        if ($this->config->get('billing.marketplace.buyer_protection.enabled', false) !== true) {
            return CheckpointOutcome::pass('Buyer protection is off, so no payout is held back and there is nothing for it to deliver.');
        }

        try {
            new BuyerProtectionClock($this->config)->assertOperable();
        } catch (BuyerProtectionMisconfigured $misconfigured) {
            return CheckpointOutcome::fail($misconfigured->getMessage());
        }

        return CheckpointOutcome::pass('Buyer protection is on, and it can hold a payout until its decision deadline.');
    }
}
