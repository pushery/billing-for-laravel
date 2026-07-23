<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Pushery\Billing\Contracts\PaymentServiceLicenseAttestation;
use Pushery\Billing\Exceptions\CustodyModeNotPermitted;

/**
 * A boot-time guard that keeps a consumer from becoming an unlicensed money holder by flipping a flag.
 *
 * The package's safe path is that the payment provider holds funds end to end — the platform never has
 * other people's money on its own account. Holding funds on a platform-owned account is a regulated
 * activity in most jurisdictions, so the platform-held mode is refused at boot unless the host has bound a
 * PaymentServiceLicenseAttestation: the deliberate, code-level act of saying "we hold a license". A config
 * flag on its own is never enough.
 *
 * It is jurisdiction-neutral: it checks a technical property — is the platform configured to hold funds
 * itself, and has it attested a license — not any specific country's statute. The legal citations live in
 * the documentation and a locale profile, never in this guard, so a consumer in any country is protected by
 * the same check.
 *
 * Mode S is untouched: the guard only ever reads its config when the marketplace is enabled, so a
 * single-merchant install adds no config key and no boot path. It forbids only what does not exist without
 * the marketplace in the first place.
 */
final readonly class CustodyGuard
{
    public function __construct(
        private Repository $config,
        private Container $container,
    ) {}

    public function verify(): void
    {
        // Only meaningful with the marketplace on. Without it there is no routed sale and nothing to hold,
        // so the guard reads no custody config at all — the neutrality guarantee.
        if (! (bool) $this->config->get('billing.marketplace.enabled', false)) {
            return;
        }

        if (! (bool) $this->config->get('billing.marketplace.custody.platform_held', false)) {
            return;
        }

        // Platform-held is requested. It is permitted only when the host has bound a license attestation —
        // the regulated path is reachable, but only as a conscious, code-level decision.
        if (! $this->container->bound(PaymentServiceLicenseAttestation::class)) {
            throw CustodyModeNotPermitted::platformHeldWithoutAttestation();
        }
    }
}
