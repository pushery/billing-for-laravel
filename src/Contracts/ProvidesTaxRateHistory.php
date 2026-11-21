<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\Tax\DatedTaxRateTable;

/**
 * The dated rate history of an installation that keeps its rates somewhere other than its configuration, a table of
 * its own for instance.
 *
 * Bound, it answers instead of the `history` of `billing.tax_matrix` wherever the package reads the history: the
 * `eu_oss` calculator, the tax of a deemed supply of goods and `billing:tax-rates:corrections`. It is asked when the
 * rates are needed, never while the application boots, so a history read from the database is not read where the
 * application boots without one. Unbound, the configuration answers as it always has.
 */
interface ProvidesTaxRateHistory
{
    /**
     * The intervals, or null where the installation prices at one table of rates.
     *
     * A table without intervals counts as no history, as an empty list does in the configuration.
     */
    public function history(): ?DatedTaxRateTable;
}
