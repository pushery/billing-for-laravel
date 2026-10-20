<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * Which rule makes the platform the supplier of goods it only facilitated the sale of.
 *
 * A platform that facilitates a sale of goods through its interface is, in two cases, treated as having
 * received the goods from the seller and supplied them to the buyer itself (Art. 14a VAT Directive, in Germany
 * § 3 Abs. 3a UStG). The sale then has two legs with the platform in the middle, the shape of a commission
 * chain, and the platform's turnover is the whole price rather than a fee.
 *
 * The two cases are separate because their documents, their place of supply and their return are separate: an
 * imported consignment is declared under the import scheme and its first leg lies outside the Union, while goods
 * already in the Union have a first leg that is exempt there and declared under the Union scheme. A sale is
 * classified once, when it is made, and never moved between them.
 */
enum DeemedSupplyOfGoods: string
{
    /** Goods imported in a consignment of an intrinsic value up to 150 euros, sold at a distance, whoever sells them. */
    case ImportedConsignment = 'imported_consignment';

    /** Goods already in the Union, sold by a seller established outside it to somebody who is not a business. */
    case WithinTheUnion = 'within_the_union';

    /** The provision that makes the platform the supplier, as a reader of a refusal or a document looks it up. */
    public function provision(): string
    {
        return match ($this) {
            self::ImportedConsignment => 'Art. 14a(1) VAT Directive',
            self::WithinTheUnion => 'Art. 14a(2) VAT Directive',
        };
    }
}
