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
    public static function providerTaxUnsupported(string $driver, string $mode = 'provider'): self
    {
        return new self(
            "billing.tax is set to '{$mode}', which defers tax to the payment provider, but the active ".
            "billing driver '{$driver}' does not compute provider tax, so no tax would be added to any ".
            "invoice. Set billing.tax to a local mode the driver can apply, or to 'none'."
        );
    }

    /**
     * The configured mode is not a tax mode at all — either not a string (billing.tax turned into an array
     * by adding a sub-key underneath it) or a name nothing resolves (a typo like 'eu_os').
     *
     * Both shapes fail the same silent way if allowed through: the calculator factory finds no match, falls
     * back to the no-op, and every invoice goes out with 0% VAT while nothing looks broken.
     *
     * @param  list<string>  $resolvable
     */
    public static function unresolvable(mixed $mode, array $resolvable): self
    {
        // The configured value is echoed back so the operator can see WHICH value was rejected, but it is
        // bounded and stripped of control characters first: this message is persisted into failure-reason
        // columns and written to logs, where an unbounded value with embedded newlines forges log structure.
        $clean = is_string($mode) ? (string) preg_replace('/[^\P{C}]++/u', '', $mode) : '';
        $shown = is_string($mode)
            ? "'".(mb_strlen($clean) > 32 ? mb_substr($clean, 0, 32).'…' : $clean)."'"
            : 'a value of type '.get_debug_type($mode);
        $modes = implode(', ', array_map(static fn (string $m): string => "'{$m}'", $resolvable));

        return new self(
            "billing.tax is set to {$shown}, which is not a tax mode this package can resolve. Valid modes ".
            "are: {$modes}. It is refused at boot rather than ignored, because an unresolvable mode otherwise ".
            'falls through to "no tax": every invoice would be issued with 0% VAT and nothing would surface '.
            'it until the VAT return did not add up.'
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
