<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Contracts\SellerOfRecordResolver;
use Pushery\Billing\Enums\SellerOfRecordPosture;
use Pushery\Billing\Exceptions\PostureNotPermitted;

/**
 * The default seller-of-record resolver: reads the posture from `billing.marketplace.seller_of_record`. A
 * global default, an optional per-sale override (per merchant or product class), an opt-in whitelist, and the
 * Art. 9a rebuttal flags — nothing here decides the posture for the consumer, it only enforces the decision
 * the consumer configured, fail-closed.
 */
final readonly class ConfigSellerOfRecordResolver implements SellerOfRecordResolver
{
    public function __construct(private Repository $config) {}

    public function resolveFor(bool $suppliesAreElectronic, ?string $override = null): SellerOfRecordPosture
    {
        $value = $override ?? $this->defaultPosture();

        $posture = SellerOfRecordPosture::tryFrom($value);

        // An unknown value, or one the consumer has not opted into, is refused: a posture is a liability
        // decision, never a value the resolver falls into.
        if ($posture === null || ! in_array($value, $this->allowedPostures(), true)) {
            throw PostureNotPermitted::notAllowed($value);
        }

        // Naming the merchant as the seller of an electronic service contradicts the Art. 9a deemed-supplier
        // presumption unless the rebuttal genuinely holds — a platform that controls terms/billing/supply
        // cannot assert it, so this stays refused by default.
        if ($posture->requiresArt9aRebuttalForElectronic() && $suppliesAreElectronic && ! $this->art9aRebuttalHolds()) {
            throw PostureNotPermitted::sellerOfRecordForElectronicSupply();
        }

        return $posture;
    }

    private function defaultPosture(): string
    {
        $value = $this->config->get('billing.marketplace.seller_of_record.default_posture', SellerOfRecordPosture::PlatformDeemedSupplier->value);

        return is_string($value) && $value !== '' ? $value : SellerOfRecordPosture::PlatformDeemedSupplier->value;
    }

    /**
     * @return list<string>
     */
    private function allowedPostures(): array
    {
        $value = $this->config->get('billing.marketplace.seller_of_record.allowed_postures', [SellerOfRecordPosture::PlatformDeemedSupplier->value]);

        if (! is_array($value)) {
            return [SellerOfRecordPosture::PlatformDeemedSupplier->value];
        }

        return array_values(array_filter($value, is_string(...)));
    }

    /**
     * The Art. 9a presumption is rebutted only when the platform asserts it AND does none of the three things
     * that make the presumption irrebuttable (setting the terms, authorizing the billing, approving the
     * supply). All four must be true — anything else leaves the platform as the deemed supplier.
     */
    private function art9aRebuttalHolds(): bool
    {
        return $this->flag('art9a_rebuttal_asserted')
            && $this->flag('no_agb_control')
            && $this->flag('no_billing_authorization')
            && $this->flag('no_supply_authorization');
    }

    private function flag(string $key): bool
    {
        return (bool) $this->config->get("billing.marketplace.seller_of_record.{$key}", false);
    }
}
