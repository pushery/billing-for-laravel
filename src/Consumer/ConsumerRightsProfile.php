<?php

declare(strict_types=1);

namespace Pushery\Billing\Consumer;

use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Exceptions\InvalidBillingConfig;

/**
 * Whether `billing.consumer_rights.profile` switches the consumer-rights regime on.
 *
 * ## Off is unset, empty or false
 *
 * Those are the three ways a `.env` file writes "off": a line left out, a line left empty, and `=false`. Each of
 * them leaves the regime off, with no extra checkout step and no changed receipt.
 *
 * ## On is a name, and the name has to select a reading
 *
 * The package ships one reading, the German one, under `de`. A host elsewhere binds readings of its own and names
 * its profile as it likes. Any other name while the shipped reading is in place is refused rather than read as on:
 * `fr` would apply German law under a French name, and `off` would switch on what it says it switches off.
 */
final readonly class ConsumerRightsProfile
{
    /** The name that selects the reading the package ships. */
    public const string SHIPPED = 'de';

    private const string KEY = 'billing.consumer_rights.profile';

    /**
     * Whether the regime is on for a gate that applies `$reading`.
     *
     * @param  object  $reading  the reading the asking gate applies, which tells the shipped one from a host's own
     *
     * @throws InvalidBillingConfig when the value is neither off nor a name that selects a reading
     */
    public static function isActive(Repository $config, object $reading): bool
    {
        $name = $config->get(self::KEY);

        if (in_array($name, [null, '', false], true)) {
            return false;
        }

        if ($name === self::SHIPPED || (is_string($name) && ! self::isShipped($reading))) {
            return true;
        }

        throw InvalidBillingConfig::forKey(self::KEY, 'is '.json_encode($name, JSON_THROW_ON_ERROR).', which selects no consumer-rights '
            ."reading: the package ships '".self::SHIPPED."', and a name of your own needs a reading of your own bound "
            .'in its place. Leave it unset, empty or false to switch the regime off');
    }

    private static function isShipped(object $reading): bool
    {
        return $reading instanceof GermanWithdrawalPolicy || $reading instanceof GermanConformityUpdatePolicy;
    }
}
