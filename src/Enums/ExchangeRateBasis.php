<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * Which rule made a particular exchange rate the correct one.
 *
 * ## There are three rules, not one, and two of them contradict each other
 *
 * | Case | Which rate | Source |
 * | --- | --- | --- |
 * | German domestic | the ministry's **monthly average**, mandatory — a daily rate needs permission | § 16 Abs. 6 UStG |
 * | EU option | the central bank's rate **at the moment the tax arose**, cross-rates via EUR | Art. 91 VAT Directive |
 * | OSS / IOSS | the central bank's rate on the **last day of the reporting period**; monthly averages are expressly excluded | UStAE 18i/18j/18k |
 *
 * Two consequences follow immediately. **"Central bank rate by default" would simply be wrong in Germany.**
 * And the OSS rule contradicts the domestic one — on the same turnover. So the choice of rule is
 * **jurisdiction knowledge**, never a core default: the same seam the rates themselves sit behind.
 *
 * ## Why the rule is frozen and not just the number
 *
 * "Which rate did you use" is the easy question. **"Why was that the right rate"** is the one an audit asks
 * first, and it is the one nobody stores. A frozen rate without its rule leaves a reviewer to guess which of
 * three regimes was in play — and any of the three produces a defensible-looking number.
 */
enum ExchangeRateBasis: string
{
    /**
     * The central bank's monthly average — the rule German domestic turnover is converted under.
     *
     * It used to be called MinistryMonthlyAverage, after the place the figure is PUBLISHED rather than the
     * place it comes from. That naming cost more than a word. The ministry table is an aggregation of central
     * bank reference rates, it carries no independent observation, and it is published behind a page that
     * refuses automated retrieval — so a basis named after it had no importer, could never have one, and was
     * queried by a reader that found nothing while the German profile handed it to every domestic conversion.
     *
     * Named for the source, and derived from the daily series this package already imports.
     */
    case CentralBankMonthlyAverage = 'central_bank_monthly_average';

    /** The central bank's reference rate on the day the tax arose. */
    case CentralBankAtTaxPoint = 'central_bank_at_tax_point';

    /** The central bank's reference rate on the last day of the reporting period — the one-stop-shop rule. */
    case CentralBankAtPeriodEnd = 'central_bank_at_period_end';
}
