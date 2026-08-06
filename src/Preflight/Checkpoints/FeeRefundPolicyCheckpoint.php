<?php

declare(strict_types=1);

namespace Pushery\Billing\Preflight\Checkpoints;

use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Contracts\GoLiveCheckpoint;
use Pushery\Billing\Contracts\SupplyRegimeResolver;
use Pushery\Billing\Enums\FeeRefundPolicy;
use Pushery\Billing\Enums\GoLiveStep;
use Pushery\Billing\Exceptions\FeeRefundPolicyNotPermitted;
use Pushery\Billing\ValueObjects\CheckpointOutcome;

/**
 * The fee-refund policy and the supply regime do not contradict each other.
 *
 * Each is defensible alone, which is exactly why the pair needs asking about. Retaining a commission on a
 * refund is an ordinary commercial choice; a commission chain is an ordinary regime. Together they describe
 * a platform keeping money for a service it never billed — and nothing downstream refuses it, because every
 * individual document the pair produces is well-formed.
 *
 * The wrongness only becomes visible in aggregate, months later: turnover on a tax return with no document
 * behind it, and merchants short by an amount no invoice explains. That is the shape of contradiction this
 * step exists for, and the reason it is asked before the first sale rather than at the first refund.
 *
 * The regime is read through the bound resolver, so a consumer who replaced it is checked against their own
 * rules — the same reason the posture checkpoint asks its resolver instead of re-reading the config.
 *
 * Not waivable: waiving it does not make the retained money billable.
 */
final readonly class FeeRefundPolicyCheckpoint implements GoLiveCheckpoint
{
    public function __construct(
        private Repository $config,
        private SupplyRegimeResolver $regimes,
    ) {}

    public function key(): string
    {
        return 'configuration.fee_refund_policy';
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
        $configured = $this->config->get('billing.marketplace.fee.refund_policy', 'refund');
        $policy = is_string($configured) ? FeeRefundPolicy::tryFrom($configured) : null;

        if (! $policy instanceof FeeRefundPolicy) {
            // An unreadable value is refused rather than defaulted. Falling back to `refund` here would be
            // the friendlier failure and the wrong one: it silently answers a question about money with
            // whatever the package prefers, on every refund, for as long as the typo survives.
            return CheckpointOutcome::fail(
                FeeRefundPolicyNotPermitted::unknown(is_string($configured) ? $configured : gettype($configured))->getMessage()
            );
        }

        $regime = $this->regimes->resolveFor();

        if (! $policy->permittedIn($regime)) {
            return CheckpointOutcome::fail(FeeRefundPolicyNotPermitted::retainInCommissionChain()->getMessage());
        }

        return CheckpointOutcome::pass(
            "The fee refund policy [{$policy->value}] is consistent with the [{$regime->value}] supply regime."
        );
    }
}
