<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * Who is the seller of record — the party the buyer legally transacts with — on a routed marketplace sale.
 * The package does NOT decide this: the choice turns on what is sold and on the platform's role, and getting
 * it wrong ships a receipt that names the wrong party. This enum makes the choice nameable, and the resolver
 * and its guards make it enforceable.
 *
 * The distinction is load-bearing for VAT:
 *
 *  - `PlatformDeemedSupplier` — for ELECTRONIC services over a portal the platform is, by law, the supplier
 *    to the buyer (Art. 9a VAT Implementing Regulation (EU) 282/2011; §3 Abs. 11a UStG; CJEU C-695/20
 *    Fenix). The presumption is irrebuttable once the platform sets the terms, authorizes the billing or
 *    approves the supply — so a normal content platform cannot avoid it. The buyer receipt names the
 *    PLATFORM; the merchant stays anonymous to the buyer.
 *
 *  - `SellerOfRecord` — the merchant is the seller in their own name (the buyer receipt names the merchant,
 *    with a real name and address). Only permitted for an electronic supply when the Art. 9a rebuttal
 *    genuinely holds (a non-electronic supply, or a platform that truly hands over terms/billing/supply).
 *
 *  - `PlatformIntermediary` — the platform is a disclosed intermediary in someone else's name (physical
 *    goods, e.g. a used-goods C2C marketplace, where Art. 9a does not apply). Only the platform's own
 *    commission is its taxable turnover; a non-business seller triggers no VAT receipt for the goods at all.
 *    This is the posture that pairs with escrow.
 */
enum SellerOfRecordPosture: string
{
    case PlatformDeemedSupplier = 'platform_deemed_supplier';
    case SellerOfRecord = 'seller_of_record';
    case PlatformIntermediary = 'platform_intermediary';

    /**
     * Whether choosing this posture for an ELECTRONIC supply requires the Art. 9a rebuttal to be asserted.
     * Only `SellerOfRecord` names a party other than the platform for an electronic supply, so only it has to
     * clear the rebuttal; the other two are consistent with the presumption.
     */
    public function requiresArt9aRebuttalForElectronic(): bool
    {
        return $this === self::SellerOfRecord;
    }
}
