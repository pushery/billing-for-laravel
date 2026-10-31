<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use DateTimeInterface;
use Pushery\Billing\Enums\BillingInterval;
use Pushery\Billing\Enums\ContractKind;

/**
 * What the package knows about a contract as it begins, for its confirmation.
 *
 * The confirmation German law asks for on a distance contract (§ 312f Abs. 2 BGB) carries the trader's identity and
 * address, the price, the terms and the withdrawal notice with the model withdrawal form. The package knows what was
 * bought and the buyer's declarations; the operator's view states the rest, and it is handed this object to state it
 * with. The moments are in the zone the owner reads dates in.
 */
final readonly class ContractConfirmation
{
    public function __construct(
        /** The operator's view, rendered into the confirmation. */
        public string $view,
        /** What is confirmed: a subscription to a tier, or the purchase of an add-on. */
        public ContractKind $kind,
        /** When the contract began: the subscription's start, or the moment of the purchase. */
        public DateTimeInterface $startedAt,
        /** The tier or the add-on, by key; null where the provider named no tier. */
        public ?string $productKey = null,
        /** Its label, in the catalog's words. */
        public ?string $productLabel = null,
        /** For a subscription the tier's price as the catalog displays it, for a purchase the amount paid. */
        public ?Money $price = null,
        /** When a subscription's first period ends. */
        public ?DateTimeInterface $periodEndsAt = null,
        /** When a subscription's trial ends, null without one. */
        public ?DateTimeInterface $trialEndsAt = null,
        /** What the buyer declared before the purchase, null where nothing is on file. */
        public ?WithdrawalConsent $consent = null,
        /**
         * For a subscription the interval the catalog charges its price in, so the price can be stated per billing
         * period; null for a purchase and where the catalog names no price.
         */
        public ?BillingInterval $interval = null,
    ) {}
}
