<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\ValueObjects\SepaCreditor;
use Pushery\Billing\ValueObjects\SepaMandateText;

/**
 * The words of a SEPA direct debit mandate in one language.
 *
 * The SEPA core rulebook asks for the mandate in a language of the payer's country. Which wording a payer's
 * country gets is configured in `billing.direct_debit.mandate_wordings`, keyed by country, and a country with no
 * entry gets no mandate. The package ships the German wording, the standard form of the German banking industry
 * for a recurring core direct debit; another language is a class of the application's own, implementing this.
 */
interface SepaMandateWording
{
    /**
     * The mandate for this payee and reference.
     *
     * @param  string  $onBehalfOf  the business the payee collects for, as its legal name
     * @param  int  $noticeDays  the days a debit is announced before it is drawn, which the agreement beside the mandate states
     */
    public function text(SepaCreditor $creditor, string $onBehalfOf, string $reference, int $noticeDays): SepaMandateText;
}
