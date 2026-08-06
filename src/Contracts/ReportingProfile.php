<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\ValueObjects\SellerRecordField;

/**
 * Which information about a seller the active reporting regime asks for, and on what basis.
 *
 * The catalog belongs to the profile rather than the core for a reason that is easy to see from the other
 * side: a consumer in another country needs an entirely different set of fields, and getting one country's
 * fields — an identifier issued under its tax law, a date of birth its statute names — would be both wrong
 * and a privacy problem, since they would be collecting data no law asks them for.
 *
 * The core knows only "a seller has a record, some of its fields are legally required and some are
 * collected ahead of time, and each has a name and a validation".
 */
interface ReportingProfile
{
    /**
     * The fields to collect from this seller.
     *
     * @param  bool  $isLegalEntity  a company rather than a person — which changes which fields exist at
     *                               all, not merely whether they are required
     * @param  bool  $reportable  whether this seller currently falls under the reporting duty. It changes
     *                            the BASIS of the fields, never the list: a seller who is not reportable
     *                            today can become one tomorrow, and collecting afterwards is the expensive
     *                            case.
     * @return list<SellerRecordField>
     */
    public function fieldsFor(bool $isLegalEntity, bool $reportable): array;
}
