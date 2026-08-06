<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * The rule that decided WHEN a supply became taxable.
 *
 * A tax point is a date, and a date on its own is not enough. Two sellers with byte-identical transactions
 * can legitimately owe tax in different periods, because their jurisdictions pick the moment differently —
 * and a prepaid year is where that stops being academic: taxed on receipt the whole tax falls in one month,
 * taxed on supply it spreads across twelve. Nothing about the resulting date says which reading produced it.
 *
 * So the basis travels with the date and is frozen beside it. Without that, a document read years later
 * cannot be checked at all: a reviewer recomputing the tax point applies TODAY's configuration to a sale
 * made under a different one, gets a different month, and has no way to tell whether the original was wrong
 * or the rule simply changed.
 *
 * The package knows only these two readings. Which applies is a jurisdiction's answer, read from its
 * profile — the package never picks one on a jurisdiction's behalf.
 */
enum TaxPointBasis: string
{
    /**
     * Taxed when the service is rendered — the tax follows the supply into its own period.
     *
     * The package's default, and deliberately so: it is what every existing document was issued under, and
     * an upgrade that silently moved tax into a different period would be the worst kind of change, because
     * the resulting documents look entirely normal.
     */
    case Supply = 'supply';

    /**
     * Taxed when the money arrived, whatever period the service belongs to.
     *
     * On a prepaid term this pulls the whole tax forward into the month of payment, including for periods
     * rendered up to a year later.
     */
    case Receipt = 'receipt';
}
