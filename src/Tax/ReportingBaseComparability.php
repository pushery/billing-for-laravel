<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Pushery\Billing\Enums\TaxationBasis;
use RuntimeException;

/**
 * Says which figures about one transaction may be checked against each other, and which may not.
 *
 * ## The two numbers that are supposed to disagree
 *
 * A platform reports what a seller received; a tax return declares what the seller is taxed on. For most
 * sales those are the same amount less some fees, and reconciling them is a good check. For a margin-taxed
 * resale they are constructed differently on purpose: one is the whole proceeds, the other is only the
 * difference between purchase and sale. On a 500 sale of goods bought for 400, that is 450 against 100.
 *
 * ## Why a reconciler needs to be told
 *
 * Nothing about either figure looks wrong, and both are correct. A reconciler comparing them reports a
 * discrepancy per transaction — and the cost of that is not a wasted afternoon: a books-do-not-reconcile
 * finding is what turns an ordinary audit into a thorough one, over a difference that was never an error.
 *
 * So the answer is not "allow a tolerance" or "explain it in a note". The two figures are incomparable by
 * construction, and a check that cannot be right must not run.
 */
final readonly class ReportingBaseComparability
{
    /**
     * Whether the reported proceeds and the taxable base may be checked against each other.
     *
     * Everything except the margin basis: for an ordinary sale, a small business, or a private seller, the
     * two figures describe the same money and a disagreement between them IS a finding.
     */
    public function comparable(?TaxationBasis $basis): bool
    {
        return ! ($basis?->taxesMarginOnly() ?? false);
    }

    /**
     * Refuse a comparison that cannot be right.
     *
     * @throws RuntimeException
     */
    public function assertComparable(?TaxationBasis $basis): void
    {
        if ($this->comparable($basis)) {
            return;
        }

        throw new RuntimeException(
            'The reported proceeds and the taxable base of a margin-taxed sale are constructed differently '
            .'on purpose — the whole proceeds against the difference between purchase and sale — so checking '
            .'one against the other reports a discrepancy that is not an error. Compare the proceeds against '
            .'what was actually paid out, and the taxable base against the margin.'
        );
    }
}
