<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * A seller-of-record posture was resolved that the consumer has NOT opted into, or is not legally available
 * for the supply — the package refuses it rather than ship a receipt that names the wrong seller.
 *
 * Two fail-closed cases:
 *
 *  - The resolved posture is outside `billing.marketplace.seller_of_record.allowed_postures`. A posture is a
 *    liability decision (who owes the VAT, who the buyer's contract is with), so it must be a deliberate
 *    opt-in, never a value the resolver falls into.
 *
 *  - `SellerOfRecord` was resolved for an ELECTRONIC supply without a genuine Art. 9a rebuttal. Naming the
 *    merchant (not the platform) as the seller of an electronic service contradicts the deemed-supplier
 *    presumption (Art. 9a VAT-IR (EU) 282/2011; CJEU C-695/20 Fenix); a platform that sets the terms,
 *    authorizes the billing or approves the supply cannot truthfully assert the rebuttal, so this is refused.
 */
final class PostureNotPermitted extends RuntimeException
{
    public static function notAllowed(string $posture): self
    {
        return new self(
            "The seller-of-record posture '{$posture}' is not in billing.marketplace.seller_of_record.".
            'allowed_postures. A posture decides who owes the VAT and who the buyer contracts with, so it '.
            'must be opted into on purpose — add it to allowed_postures only when you mean it.'
        );
    }

    public static function sellerOfRecordForElectronicSupply(): self
    {
        return new self(
            'The seller_of_record posture names the merchant (not the platform) as the seller, but this is an '.
            'electronically-supplied service — where the platform is the deemed supplier by law (Art. 9a VAT '.
            'Implementing Regulation (EU) 282/2011; CJEU C-695/20 Fenix) unless the rebuttal genuinely holds. '.
            'Set billing.marketplace.seller_of_record.art9a_rebuttal_asserted (and the three no_*_control '.
            'flags) to true ONLY if the platform truly does not set the terms, authorize the billing, or '.
            'approve the supply — otherwise use platform_deemed_supplier.'
        );
    }
}
