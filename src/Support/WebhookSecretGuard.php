<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Pushery\Billing\Exceptions\WebhookSigningNotConfigured;
use Throwable;

/**
 * The boot-time fail-loud guard for a driver's credentials.
 *
 * What it owns is the POLICY, not any one message: a credential a driver cannot work without must be
 * present in production, a blank value counts as absent (that is what a half-finished deployment leaves,
 * and it reads as configured to anybody looking at the file), and outside production it stays silent so a
 * bare checkout still runs.
 *
 * Each driver names its own failure, because the credentials are not the same thing. Stripe cannot verify
 * a webhook without its signing secret; Mollie cannot make a single API call without its key, and demanding
 * a SIGNING secret from Mollie would refuse to boot every install still on its legacy webhook generation,
 * which carries no signature at all. One policy, several credentials, one place.
 *
 * The class keeps its original name so a consumer referencing it is not broken by a rename. What it guards
 * has widened; what it is called has not.
 */
final class WebhookSecretGuard
{
    /** The webhook-signing case, kept as its own call so its message stays specific. */
    public function ensureConfigured(string $driver, string $environment, ?string $secret): void
    {
        $this->ensureCredential(
            $environment,
            $secret,
            static fn (): Throwable => WebhookSigningNotConfigured::forDriver($driver),
        );
    }

    /**
     * Point out a missing credential in production without refusing to boot.
     *
     * The middle setting between "required" and "silent", and it exists for a real case rather than as a
     * softer option: a credential that IS needed on some installs and genuinely does not exist on others,
     * where nothing in the process can tell which one it is looking at. Requiring it locks out the installs
     * that are already correct; saying nothing lets the ones that need it run without it. A warning is the
     * only answer that is wrong in neither direction.
     *
     * @param  callable(): void  $onMissing
     */
    public function warnWhenAbsent(string $environment, ?string $credential, callable $onMissing): void
    {
        if ($environment !== 'production') {
            return;
        }

        if ($credential === null || trim($credential) === '') {
            $onMissing();
        }
    }

    /**
     * Require a credential in production, or throw what the caller decides.
     *
     * The exception arrives as a factory rather than a class name so the driver can raise its OWN, fully
     * worded failure — a missing Mollie key reported as "webhook signing not configured" would send an
     * operator to the wrong setting entirely.
     *
     * @param  callable(): Throwable  $onMissing
     */
    public function ensureCredential(string $environment, ?string $credential, callable $onMissing): void
    {
        if ($environment !== 'production') {
            return;
        }

        if ($credential === null || trim($credential) === '') {
            throw $onMissing();
        }
    }
}
