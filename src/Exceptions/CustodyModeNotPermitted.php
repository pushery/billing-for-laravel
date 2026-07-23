<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * The configured custody mode is not one the package will run without an explicit license attestation.
 *
 * Refusing at boot is the point: the alternative is a platform quietly holding other people's money on its
 * own account because a flag was set, with nothing to signal that this is a regulated activity. The message
 * is jurisdiction-neutral — it names the technical property (funds held on the platform's own account),
 * not a specific country's law, because the same property is regulated under different names in different
 * places.
 */
final class CustodyModeNotPermitted extends RuntimeException
{
    public static function platformHeldWithoutAttestation(): self
    {
        return new self(
            'billing.marketplace.custody.platform_held is true, which means the platform holds other '.
            "people's funds on its own account — a regulated activity in most jurisdictions. The package ".
            'will not enable it on a config flag alone. Bind an implementation of '.
            'Pushery\\Billing\\Contracts\\PaymentServiceLicenseAttestation to declare, in code, that you '.
            'hold the required license; or leave the funds with the payment provider (the default, '.
            'platform_held = false), where no such license is needed.'
        );
    }
}
