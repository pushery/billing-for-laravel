<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\Enums\ExchangeRateBasis;
use Pushery\Billing\Enums\ExchangeRateLayer;

/**
 * A jurisdiction profile that says which conversion rule its PERIODIC RETURN is filed under.
 *
 * ## Why this is a second contract and not a second method on the first
 *
 * {@see SuppliesExchangeRateBasis} states, as a decision rather than an omission, that it answers for the
 * document layer alone: "a profile that answered for all three here would be claiming authority over
 * documents this package never writes." Widening it would quietly reverse that, and the reversal would be
 * invisible to anyone reading the interface afterwards.
 *
 * The layers also do not travel together. An installation can have a settled document rule and file no
 * periodic return at all, or file one under a regime this package knows nothing about. Two contracts let a
 * profile answer the half it actually owns; one contract would make it answer both or neither.
 *
 * ## What "reporting" means here, and why it is not simply the EU rule
 *
 * For a one-stop-shop return the answer is fixed EU-wide — the central bank's rate on the last day of the
 * tax period, with the next publication day where that day has none (§ 16 (6) sentence 4 UStG, Art. 369h(2)
 * VAT Directive). It is tempting to hard-code exactly that and be done.
 *
 * It would also be wrong for every consumer outside that regime. A filing elsewhere converts under its own
 * authority's rule, and "the ECB at quarter end" is not a neutral default there — it is one jurisdiction's
 * answer wearing the costume of a general one. So the rule is asked for, and a profile that does not
 * implement this freezes no reporting rate, exactly as its sibling does for documents.
 *
 * @see ExchangeRateLayer for why one sale lawfully carries more than one euro figure
 */
interface SuppliesReportingExchangeRateBasis
{
    /** The rule this jurisdiction's periodic return converts under. */
    public function reportingExchangeRateBasis(): ExchangeRateBasis;
}
