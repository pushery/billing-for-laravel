<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Carbon\CarbonInterface;
use Pushery\Billing\Enums\UsTaxFormType;

/**
 * A jurisdiction profile that asks sellers to declare where they are taxed, and knows its region limits.
 *
 * A marker a profile opts into, the same shape as {@see SuppliesTaxRates} and for the same reason: the
 * contract every profile satisfies is about the go-live checklist, and widening it would be a fatal error
 * in a consumer's own profile class.
 *
 * It hangs here rather than on any one country's profile because that is the whole difference between a
 * package that works elsewhere and a package with a foreign annex bolted onto its home jurisdiction. A
 * profile that does not implement this asks nothing and reads no limits — which is exactly what an operator
 * with no exposure to the regime should experience.
 *
 * The limits live in the profile with a date attached because they are not constants: they are somebody's
 * published figures at a moment. A limit in a core class is a limit that silently goes stale, and the way
 * you find out is that the platform registered a year late.
 */
interface CollectsSellerTaxDeclarations
{
    /**
     * Which declarations this jurisdiction asks a seller for.
     *
     * @return list<UsTaxFormType>
     */
    public function sellerDeclarations(): array;

    /**
     * The per-region limits that decide when the regime has to be switched on, keyed by region code.
     *
     * Two figures per region, because a region typically publishes both and either one triggers on its own:
     * many small transactions cross the count while staying under the money, and one large one does the
     * reverse.
     *
     * @return array<string, array{net_minor: int, transactions: int}>
     */
    public function regionalLimits(): array;

    /** The day those limits were known to be correct, so their age can be reported rather than assumed. */
    public function regionalLimitsValidFrom(): CarbonInterface;
}
