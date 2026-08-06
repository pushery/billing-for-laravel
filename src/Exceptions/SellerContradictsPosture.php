<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use Pushery\Billing\Enums\SellerOfRecordPosture;
use RuntimeException;

/**
 * The seller a document names contradicts the seller-of-record posture frozen onto it.
 *
 * The two are one decision seen twice: the posture is the role, the seller is the party that role resolves
 * to. When the platform is the deemed supplier, IT is the seller; when it merely arranges the sale, or the
 * merchant sells in their own name, the MERCHANT is. A document that names the merchant as seller under a
 * deemed-supplier posture would put a creator in front of the buyer as the seller — the exact outcome the
 * deemed-supplier rule exists to prevent — so the write is refused rather than left to issue it.
 */
final class SellerContradictsPosture extends RuntimeException
{
    public static function platformMustSell(SellerOfRecordPosture $posture): self
    {
        return new self(
            "The seller-of-record posture [{$posture->value}] makes the platform the seller, but the document "
            .'names a different party. Under a deemed-supplier posture the platform is the seller toward the '
            .'buyer; naming the merchant would identify a creator to the buyer as the seller. Snapshot the '
            .'platform company as the seller, or resolve a posture that names the merchant.'
        );
    }

    public static function merchantMustSell(SellerOfRecordPosture $posture): self
    {
        return new self(
            "The seller-of-record posture [{$posture->value}] makes the merchant the seller, but the document "
            .'names the platform. When the platform only arranges the sale, or the merchant sells in their own '
            .'name, the merchant is the seller toward the buyer — the platform naming itself would misstate who '
            .'sold. Snapshot the merchant as the seller, or resolve the deemed-supplier posture.'
        );
    }
}
