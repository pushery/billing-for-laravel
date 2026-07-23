<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

/**
 * A host's declaration that it is licensed to hold other people's money on its own account.
 *
 * Holding funds on a platform-owned account — rather than leaving them with the payment provider — is a
 * regulated activity almost everywhere. The package will not enable that path on a config flag alone,
 * because a consumer who does not know the rules could become an unlicensed money holder without anything
 * failing. Binding an implementation of this contract is the deliberate, code-level act that says "we hold a
 * license and take responsibility for it"; without it, the platform-held custody mode refuses to boot.
 *
 * This is a MARKER: the package does not inspect what the attestation says, and binding one is not legal
 * advice or a substitute for an actual license. It is the switch that makes the regulated path reachable, so
 * that reaching it is always a conscious decision and never a default.
 */
interface PaymentServiceLicenseAttestation
{
    /**
     * A short, human-readable reference to the license or permission being attested — a registration
     * number, an authority name, whatever identifies it in the host's own records. It is surfaced in
     * diagnostics so an operator can see WHICH attestation unlocked the regulated path, never interpreted
     * by the package.
     */
    public function reference(): string;
}
