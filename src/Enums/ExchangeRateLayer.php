<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * Which conversion of one sale a frozen rate belongs to.
 *
 * ## Why one sale carries more than one euro figure, lawfully
 *
 * The same turnover is converted more than once, under rules that do not agree, and that divergence is
 * sanctioned rather than a defect. The document takes the ministry's monthly average; the one-stop-shop
 * return takes the central bank's rate at the end of the reporting quarter and expressly excludes monthly
 * averages; the payout is whatever the money actually moved at.
 *
 * A single frozen rate per sale cannot express that. It would force one of the three to be re-derived
 * later, at whatever the rate is then — which is how a correction reverses an amount nobody ever declared.
 *
 * So a sale carries one frozen rate PER LAYER, and this names the layers. It is jurisdiction-free on
 * purpose: which rule fills which layer is the profile's business, and a consumer elsewhere uses the same
 * three words for their own rules.
 */
enum ExchangeRateLayer: string
{
    /** What the settlement document states. */
    case Document = 'document';

    /** What the one-stop-shop return reports, which is a different figure for the same sale. */
    case Reporting = 'reporting';

    /** What the money actually moved at when the merchant was paid. */
    case Payout = 'payout';
}
