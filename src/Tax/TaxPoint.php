<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Enums\TaxPointBasis;
use Pushery\Billing\ValueObjects\ServicePeriod;

/**
 * When the tax on a period falls due — when the money arrived, or when the service was rendered.
 *
 * A year paid up front is the case that separates the two. Taxing it as it is rendered spreads it over
 * twelve months; taxing it on receipt puts all of it in the month the money came. Some jurisdictions require
 * the second, and the difference is not small: on a prepaid year the whole tax is either declared now or
 * eleven months late, and nothing in the documents themselves says which is meant.
 *
 * Both legs of a chained transaction must use the SAME answer. If the sale is taxed on receipt while the
 * merchant's side is taxed period by period, the input tax lags the output tax by up to eleven months —
 * a difference nobody notices and every reconciliation pays for.
 *
 * Neutral by construction: the package knows only "receipt" or "supply", and which one applies is a
 * jurisdiction's rule, read from its profile. Supply is the default, because it is what the package has
 * always done and a silent change of tax period is the last thing an upgrade should do.
 */
final readonly class TaxPoint
{
    public function __construct(private Repository $config) {}

    /** Whether this installation's jurisdiction taxes a prepayment when it is received. */
    public function onReceipt(): bool
    {
        return (bool) $this->config->get('billing.tax_point_on_receipt', false);
    }

    /** The rule this installation's jurisdiction applies. */
    public function basis(): TaxPointBasis
    {
        return $this->onReceipt() ? TaxPointBasis::Receipt : TaxPointBasis::Supply;
    }

    /**
     * The tax point for a period, WITH the rule that produced it.
     *
     * Preferred over `forPeriod()` everywhere a result is stored or handed on. A bare date cannot be
     * checked afterwards: recomputing it applies today's configuration to a sale made under a different
     * one, so a reviewer who arrives at a different month cannot tell a wrong original from a changed rule.
     */
    public function decideFor(ServicePeriod $period, CarbonImmutable $paidOn): TaxPointDecision
    {
        return new TaxPointDecision(
            on: $this->forPeriod($period, $paidOn),
            basis: $this->basis(),
            taxedAhead: $this->taxedAhead($period, $paidOn),
        );
    }

    /**
     * The date whose period the tax on this service period belongs to.
     *
     * Taxed on receipt, that is the day the money arrived — for every period of the term, including ones a
     * year away. Otherwise it is the period's own start: the tax follows the service.
     */
    public function forPeriod(ServicePeriod $period, CarbonImmutable $paidOn): CarbonImmutable
    {
        return $this->onReceipt() ? $paidOn : $period->from;
    }

    /**
     * Whether a period is taxed somewhere other than in itself — the case that has to be visible.
     *
     * A period taxed on receipt, months before it is rendered, looks like an ordinary period on every
     * document. This is what lets a caller say so.
     */
    public function taxedAhead(ServicePeriod $period, CarbonImmutable $paidOn): bool
    {
        return $this->forPeriod($period, $paidOn)->lessThan($period->from);
    }
}
