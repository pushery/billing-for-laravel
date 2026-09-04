<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Mollie;

use Illuminate\Support\Facades\Config;
use Mollie\Api\Types\MandateMethod;
use Pushery\Billing\ValueObjects\DriverCapabilities;
use ReflectionClass;

/**
 * What the Mollie driver promises.
 *
 * Every native flag is false, and that is the whole design rather than a gap: Mollie has no customer
 * portal, no provider-side tax, no metered pricing, no proration and no customer balance, so the package
 * fills each with its own engine. A capability is a promise the PACKAGE keeps, not a description of what a
 * provider could do — claiming one the driver does not wire sends a screen looking for a feature that is
 * not there, which is worse than reporting the gap and having it filled locally.
 *
 * The recurring set is DERIVED from the SDK's own `MandateMethod`. Typing the list out would be a factual
 * claim about somebody else's product with nothing holding it true, and it would rot silently the day
 * Mollie adds a method — in the direction that refuses a legitimate mandate.
 */
final readonly class MollieCapabilities
{
    public static function make(): DriverCapabilities
    {
        $recurring = self::recurringMethods();

        return new DriverCapabilities(
            supportsHostedPortal: false,
            supportsProviderTax: false,
            supportsMeteredNative: false,
            supportsProviderProration: false,
            supportsProviderCredit: false,
            availablePaymentMethods: self::availableMethods($recurring),
            recurringCapableMethods: $recurring,
        );
    }

    /**
     * The methods a mandate can exist for, straight from the SDK's vocabulary.
     *
     * @return list<string>
     */
    private static function recurringMethods(): array
    {
        /** @var list<string> $methods */
        $methods = array_values(new ReflectionClass(MandateMethod::class)->getConstants());

        return $methods;
    }

    /**
     * What this account offers at checkout. Configurable because Mollie enables methods PER ACCOUNT, so a
     * fixed list would be wrong for most installs in both directions at once.
     *
     * The fallback is the recurring set rather than an empty list: empty reads as "this account can take
     * no payments" and every screen that asks would render nothing. The mandate methods are the honest
     * floor — they are what the driver can actually complete a subscription with.
     *
     * @param  list<string>  $recurring
     * @return list<string>
     */
    private static function availableMethods(array $recurring): array
    {
        $configured = Config::get('billing.mollie.methods');

        if (! is_array($configured) || $configured === []) {
            return $recurring;
        }

        return array_values(array_filter($configured, is_string(...)));
    }
}
