<?php

declare(strict_types=1);

namespace Pushery\Billing\Preflight\Checkpoints;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Pushery\Billing\Contracts\GoLiveCheckpoint;
use Pushery\Billing\Contracts\PaymentServiceLicenseAttestation;
use Pushery\Billing\Enums\GoLiveStep;
use Pushery\Billing\ValueObjects\CheckpointOutcome;

/**
 * Who holds the money, and whether the operator is allowed to.
 *
 * The safe path — the payment provider holds funds end to end — passes and says so, because "the platform
 * never touches other people's money" is worth reading in the report rather than inferring from silence.
 * The platform-held path passes only with a bound license attestation, which is the same condition the
 * custody guard enforces at boot, surfaced here one step earlier.
 *
 * Not waivable: waiving it would let a consumer configure themselves into unlicensed money holding, which
 * is precisely the outcome the attestation exists to make impossible by accident.
 */
final readonly class CustodyAttestationCheckpoint implements GoLiveCheckpoint
{
    public function __construct(
        private Repository $config,
        private Container $container,
    ) {}

    public function key(): string
    {
        return 'configuration.custody';
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
        if (! (bool) $this->config->get('billing.marketplace.custody.platform_held', false)) {
            return CheckpointOutcome::pass(
                'The payment provider holds funds end to end; the platform never holds other people\'s money '.
                'on its own account.'
            );
        }

        if (! $this->container->bound(PaymentServiceLicenseAttestation::class)) {
            return CheckpointOutcome::fail(
                'billing.marketplace.custody.platform_held is on, which makes the platform a holder of other '.
                'people\'s funds — a regulated activity. Bind an implementation of '.
                'Pushery\\Billing\\Contracts\\PaymentServiceLicenseAttestation to declare the license, or turn '.
                'platform_held off and leave the funds with the provider.'
            );
        }

        $reference = $this->container->make(PaymentServiceLicenseAttestation::class)->reference();

        return CheckpointOutcome::pass(
            "The platform holds funds itself under the bound license attestation [{$reference}]."
        );
    }
}
