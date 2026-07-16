<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

/**
 * One tier's card on a pricing surface — the config-authoritative shape the in-app upgrade grid AND the
 * public /pricing page both render from, so the two can never drift into showing different promises.
 *
 * Everything here is derived from the tier's config (its label, display price, BYOK flag, ordered feature
 * bullets, and an optional highlight / badge); nothing is hard-coded in a view. The bullets arrive already
 * resolved to the current locale, so the view only renders them.
 */
final readonly class PricingCard
{
    /**
     * @param  list<string>  $bullets  the tier's feature bullets, resolved to the current locale, in order
     */
    public function __construct(
        public string $tierKey,
        public string $label,
        public ?Money $priceDisplay,
        public bool $byok,
        public array $bullets,
        public bool $highlighted = false,
        public ?string $badge = null,
    ) {}
}
