<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\Enums\ExchangeRateBasis;
use Pushery\Billing\Enums\ExchangeRateLayer;

/**
 * A jurisdiction profile that says which conversion rule its RECAPITULATIVE STATEMENT is filed under.
 *
 * A contract of its own for the reason {@see SuppliesReportingExchangeRateBasis} gives: the layers do not
 * travel together. The statement is a national declaration of the reverse-charged supplies to businesses in
 * other member states, and it converts under the national rule for the taxable amount. In Germany that is
 * § 16 (6) sentence 1 UStG, the ministry's average for the month the supply was made, which is not the rule the
 * one-stop-shop return converts under.
 *
 * A profile that does not implement this freezes no statement rate, and the statement refuses a sale in another
 * currency rather than leaving it out: a statement short by a sale reconciles with nothing that was sold.
 *
 * @see ExchangeRateLayer::RecapitulativeStatement for the layer the rate is frozen into
 */
interface SuppliesRecapitulativeStatementExchangeRateBasis
{
    /** The rule this jurisdiction's recapitulative statement converts under. */
    public function recapitulativeStatementExchangeRateBasis(): ExchangeRateBasis;
}
