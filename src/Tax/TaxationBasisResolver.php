<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Enums\TaxationBasis;
use Pushery\Billing\Marketplace\SellerActivityThreshold;
use Pushery\Billing\Marketplace\SmallBusinessThresholdBreach;
use Pushery\Billing\Marketplace\SmallBusinessThresholdMonitor;

/**
 * On what basis a seller is taxed — derived where nobody has said, and always overridable.
 *
 * ## The declaration wins, and that is not a convenience
 *
 * Whether somebody is trading is a qualitative judgement: what they sell, how they source it, how they
 * present themselves. A count of sales and a sum of proceeds is a **proxy** for that judgement, and a good
 * one — it is how a platform notices somebody who has quietly become a business. It is not the judgement.
 *
 * Treating the proxy as the answer produces two failures with different victims. Somebody genuinely trading
 * below the counts is treated as private and issues nothing they owe. Somebody who cleared out an
 * inheritance in one year crosses the counts and is treated as a business they are not. Neither is fixable
 * afterwards by counting more carefully, so a declared basis always wins over a derived one.
 *
 * ## Order among the derived cases
 *
 * Below the trading counts there is no business, so nothing else can apply. Above them, relief by size is
 * checked before anything else, because a seller who owes no tax cannot be taxed on a margin either — the
 * margin scheme decides *what* is taxed, not *whether*. Which is also why margin is never derived: it is a
 * choice a reseller makes about goods they bought without deductible tax, and no counter can see that.
 */
final readonly class TaxationBasisResolver
{
    public function __construct(
        private SellerActivityThreshold $trading,
        private SmallBusinessThresholdMonitor $size,
    ) {}

    /**
     * The basis to freeze onto a transaction.
     *
     * @param  ?TaxationBasis  $declared  what the seller (or an operator reviewing them) has stated
     */
    public function basisFor(
        Model $seller,
        string $currency,
        int $year,
        int $sales,
        int $proceedsMinor,
        ?TaxationBasis $declared = null,
        ?int $foundingYear = null,
    ): TaxationBasis {
        if ($declared instanceof TaxationBasis) {
            return $declared;
        }

        if (! $this->trading->requiresStatusDeclaration($sales, $proceedsMinor)) {
            return TaxationBasis::Private;
        }

        return $this->relievedBySize($seller, $currency, $year, $foundingYear)
            ? TaxationBasis::SmallBusiness
            : TaxationBasis::Standard;
    }

    /**
     * Whether a derived basis is only a proxy's answer, so a caller can ask before acting on it.
     *
     * The distinction matters at exactly one place: a document that states tax. Stating tax on a proxy's
     * say-so puts a number on a document that the seller may not owe, and an incorrectly stated tax is owed
     * anyway by whoever wrote it down.
     */
    public function isDerived(?TaxationBasis $declared): bool
    {
        return ! $declared instanceof TaxationBasis;
    }

    /**
     * Whether a derived small-business verdict is standing on an empty ledger.
     *
     * A different question from `isDerived()`, and both matter at the same place. `isDerived()` asks whether
     * anybody STATED the basis; this asks whether the figures behind a derived one exist at all. A verdict
     * of "relieved by size" computed from no rows is not a finding — it is the absence of one, wearing the
     * same shape.
     *
     * It answers rather than refuses, because refusing would be wrong for the install where the emptiness is
     * correct: a single seller has no merchants and never had this question. The caller about to state tax
     * on a document is the one that knows which situation it is in, and it is the only place where getting
     * this wrong costs anything — an incorrectly stated tax is owed by whoever wrote it down.
     */
    public function restsOnNoEarnings(Model $seller, string $currency, int $year): bool
    {
        return ! $this->size->hasObservedEarnings($seller, $currency, $year);
    }

    /** Whether relief by size applies this year, on either year's figures. */
    private function relievedBySize(Model $seller, string $currency, int $year, ?int $foundingYear): bool
    {
        // Exceeding in the previous year removes the relief for this one; exceeding within this year removes
        // it from the crossing sale. Either is enough to say the seller is not relieved.
        return ! $this->size->currentYearBreach($seller, $currency, $year, $foundingYear) instanceof SmallBusinessThresholdBreach
            && ! $this->size->previousYearExceeded($seller, $currency, $year);
    }
}
