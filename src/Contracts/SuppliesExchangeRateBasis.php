<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\Enums\ExchangeRateBasis;
use Pushery\Billing\Enums\ExchangeRateLayer;

/**
 * A jurisdiction profile that says which conversion rule its documents are issued under.
 *
 * ## Why a profile answers this and the package cannot
 *
 * The three rules genuinely contradict each other on the same turnover: German domestic takes the
 * ministry's monthly average, the EU option takes the central bank's rate at the tax point, and the
 * one-stop-shop takes the central bank's rate at period end while expressly excluding monthly averages. A
 * package-level default would be wrong for somebody by law rather than by oversight.
 *
 * So the question is asked of the profile, and a profile that does not implement this is not answered for.
 * An installation with no FX rule declared simply does not freeze a rate — which is the honest outcome, and
 * strictly better than freezing one under a rule nobody chose.
 *
 * ## Only the document layer, deliberately
 *
 * A sale carries more than one lawful conversion — see {@see ExchangeRateLayer}.
 * This names the rule for the layer the SETTLEMENT DOCUMENT is issued under, which is the one the package
 * produces and can therefore be responsible for. The reporting layer belongs to whoever files the return
 * and the payout layer to whoever moves the money; a profile that answered for all three here would be
 * claiming authority over documents this package never writes.
 */
interface SuppliesExchangeRateBasis
{
    /** The rule a settlement document's conversion is correct under in this jurisdiction. */
    public function documentExchangeRateBasis(): ExchangeRateBasis;
}
