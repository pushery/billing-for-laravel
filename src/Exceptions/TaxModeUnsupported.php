<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * The configured tax mode cannot be applied by the active billing driver.
 *
 * Like MeteringUnsupported, this refuses to boot rather than degrade, because the degraded behavior is a
 * silent one: a locally-computed VAT that the money-mover never actually charges, or a "provider" mode on a
 * driver that computes no tax — either way the customer is under-charged and nothing looks broken until the
 * VAT return does not add up.
 */
final class TaxModeUnsupported extends RuntimeException
{
    public static function providerTaxUnsupported(string $driver): self
    {
        return new self(
            "billing.tax is set to 'provider', but the active billing driver '{$driver}' does not compute ".
            'provider tax, so no tax would be added to any invoice. Set billing.tax to a local mode the '.
            "driver can apply, or to 'none'."
        );
    }

    public static function localTaxUnapplicable(string $driver, string $mode): self
    {
        return new self(
            "billing.tax is set to '{$mode}', a locally-computed tax mode, but the active billing driver ".
            "'{$driver}' defers tax to the provider and never applies a locally-computed figure to what the ".
            'customer is charged. The VAT would be computed and never collected. Use billing.tax=\'provider\' '.
            "to have the provider charge tax, or 'none'."
        );
    }
}
