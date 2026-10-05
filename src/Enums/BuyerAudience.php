<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

use InvalidArgumentException;

/**
 * Who may buy an offer: a tier or an add-on, set as `buyers` on its entry in `billing.tiers` or `billing.addons`.
 */
enum BuyerAudience: string
{
    /** Anyone who reaches the offer, which is every offer that does not say otherwise. */
    case Anyone = 'anyone';

    /**
     * Only a business. The offer is shown and sold to an owner the bound `IdentifiesBusinessBuyers` names as one,
     * and no consumer right of withdrawal arises from it.
     */
    case Business = 'business';

    /**
     * The audience a configuration value names.
     *
     * An unset value is anyone. Anything else that is not one of the cases is refused rather than read as anyone,
     * because a typo there would sell an offer meant for businesses to consumers.
     */
    public static function fromConfig(mixed $value, string $key): self
    {
        if ($value === null) {
            return self::Anyone;
        }

        $resolved = is_string($value) ? self::tryFrom($value) : null;

        if (! $resolved instanceof self) {
            throw new InvalidArgumentException("{$key}: buyers must be one of anyone, business.");
        }

        return $resolved;
    }
}
